<?php

/**
 * The live/<uuid> post-processing copy must be removed completely on network shares.
 *
 * Storage::deleteDirectory() deletes while iterating the directory. On an SMB/CIFS
 * mount (measured on a real share: 11 of 61 files left, every time) the iteration
 * skips entries, rmdir then fails, and with the dvr disk's 'throw' => false nothing is
 * logged — so every recording left part of its temp copy behind. The test disk below
 * reproduces that behaviour; deleting individual files and empty directories works,
 * exactly as on the share.
 */

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Services\DvrPostProcessorService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;

/** Local adapter whose recursive deleteDirectory() misbehaves like it does on SMB. */
class SmbLikeLocalAdapter extends LocalFilesystemAdapter
{
    public function __construct(private string $rootDir)
    {
        parent::__construct($rootDir);
    }

    public function deleteDirectory(string $path): void
    {
        $entries = glob($this->rootDir.'/'.$path.'/*') ?: [];
        if ($entries === []) {
            parent::deleteDirectory($path);

            return;
        }
        foreach ($entries as $i => $entry) {
            if ($i % 6 !== 5 && is_file($entry)) {
                unlink($entry); // the share's directory iterator skips every few entries
            }
        }
        throw UnableToDeleteDirectory::atLocation($path, 'Directory not empty');
    }
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/dvr-smb-like-'.uniqid();
    mkdir($this->root, 0755, true);
    $adapter = new SmbLikeLocalAdapter($this->root);
    Storage::set('dvr', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $this->root, 'throw' => false]));
    Queue::fake();
    Http::preventStrayRequests();
});

/** Deletes files normally but never removes a directory, like a share that keeps an empty folder busy. */
class DirectoryKeepingLocalAdapter extends LocalFilesystemAdapter
{
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Device or resource busy');
    }
}

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

function fillTempDir(string $dir, int $files): void
{
    Storage::disk('dvr')->put("{$dir}/live.m3u8", "#EXTM3U\n");
    for ($i = 0; $i < $files; $i++) {
        Storage::disk('dvr')->put(sprintf('%s/live%06d.ts', $dir, $i), str_repeat('x', 1024));
    }
}

it('reproduces the network-share problem with a plain deleteDirectory()', function (): void {
    fillTempDir('live/abc', 60);

    Storage::disk('dvr')->deleteDirectory('live/abc');

    expect(Storage::disk('dvr')->allFiles('live/abc'))->not->toBeEmpty();
});

it('deleteTempDirectory() removes every file and the directory itself', function (): void {
    fillTempDir('live/abc', 60);
    Storage::disk('dvr')->put('live/abc/sub/extra.ts', 'x');

    $left = app(DvrPostProcessorService::class)->deleteTempDirectory('dvr', 'live/abc');

    expect($left)->toBe(0)
        ->and(Storage::disk('dvr')->directoryExists('live/abc'))->toBeFalse()
        ->and(Storage::disk('dvr')->directoryExists('live'))->toBeTrue();
});

it('leaves no temp copy behind after post-processing on a network share', function (): void {
    if (! is_executable('/usr/bin/ffmpeg')) {
        $this->markTestSkipped('ffmpeg required');
    }
    config(['dvr.ffmpeg_path' => '/usr/bin/ffmpeg']);
    $setting = DvrSetting::factory()->create(['dvr_output_format' => 'mkv', 'enable_metadata_enrichment' => false]);
    $recording = DvrRecording::factory()->create([
        'user_id' => $setting->user_id,
        'dvr_setting_id' => $setting->id,
        'status' => DvrRecordingStatus::PostProcessing,
        'season' => null,
        'episode' => null,
    ]);
    $dir = Storage::disk('dvr')->path("live/{$recording->uuid}");
    mkdir($dir, 0755, true);
    exec(sprintf(
        'ffmpeg -v error -y -f lavfi -i testsrc2=size=160x90:rate=25 -t 24 -c:v libx264 -preset ultrafast -g 25 '
        .'-f hls -hls_time 1 -hls_list_size 0 -hls_segment_filename %s %s',
        escapeshellarg($dir.'/live%06d.ts'),
        escapeshellarg($dir.'/live.m3u8'),
    ), $out, $code);
    expect($code)->toBe(0)
        ->and(count(glob($dir.'/*.ts')))->toBeGreaterThan(12);

    app(DvrPostProcessorService::class)->run($recording->fresh());

    expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and(Storage::disk('dvr')->directoryExists("live/{$recording->uuid}"))->toBeFalse();
});

it('reports incomplete cleanup when the emptied temp directory itself cannot be removed', function (): void {
    $adapter = new DirectoryKeepingLocalAdapter($this->root);
    Storage::set('dvr', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $this->root, 'throw' => false]));
    fillTempDir('live/abc', 5);

    $left = app(DvrPostProcessorService::class)->deleteTempDirectory('dvr', 'live/abc');

    expect(Storage::disk('dvr')->allFiles('live/abc'))->toBeEmpty()
        ->and(Storage::disk('dvr')->directoryExists('live/abc'))->toBeTrue()
        ->and($left)->toBe(1); // the directory itself is still there
});

it('counts files and sub-directories that are still there', function (): void {
    $adapter = new class($this->root) extends DirectoryKeepingLocalAdapter
    {
        public function delete(string $path): void
        {
            throw UnableToDeleteFile::atLocation($path, 'Permission denied');
        }
    };
    Storage::set('dvr', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $this->root, 'throw' => false]));
    Storage::disk('dvr')->put('live/abc/sub/extra.ts', 'x');

    $left = app(DvrPostProcessorService::class)->deleteTempDirectory('dvr', 'live/abc');

    expect($left)->toBe(3); // live/abc, live/abc/sub and extra.ts
});

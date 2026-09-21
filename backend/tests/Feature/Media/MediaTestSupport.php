<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * Media feature tests run on the `local` disk, as phpunit.xml forces (docs/03-architecture/storage.md
 * §Configuration matrix). Storage::fake() alone is not enough here: the filesystem tenancy
 * bootstrapper forgets and re-roots the disk whenever a tenant is initialised, so the ROOT is
 * redirected into a throw-away directory instead, keeping the real `tenants/{id}/` layout. The
 * directory is private to this suite because several test runs share the container.
 */

function mediaTestRoot(): string
{
    return storage_path('framework/testing/disks/media-'.(getenv('TEST_DATABASE') ?: 'default'));
}

function useMediaTestDisk(): void
{
    (new Filesystem)->deleteDirectory(mediaTestRoot());
    config([
        'helpdesk.media.disk' => 'local',
        'filesystems.disks.local.root' => mediaTestRoot().'/central',
        'tenancy.filesystem.root_override.local' => mediaTestRoot().'/tenants/%tenant%',
    ]);
    Storage::forgetDisk('local');
}

function cleanMediaTestDisk(): void
{
    (new Filesystem)->deleteDirectory(mediaTestRoot());
}

/** Absolute path of a tenant-relative key, i.e. what the bucket key would be. */
function mediaObjectPath(Tenant $tenant, string $key): string
{
    return mediaTestRoot()."/tenants/{$tenant->getKey()}/{$key}";
}

function putMediaObject(Tenant $tenant, string $key, string $bytes): void
{
    $tenant->run(fn () => Storage::disk('local')->put($key, $bytes));
}

function pngBytes(int $width = 8, int $height = 6): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 200));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** @param array<string, string> $entries */
function zipBytes(array $entries): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'zip-fixture-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function docxBytes(): string
{
    return zipBytes([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
    ]);
}

/** POST /v1/media/intent for the given bytes; `$size` overrides the declared size. */
function requestIntent(string $filename, string $bytes, string $mime, ?int $size = null): TestResponse
{
    return test()->postJson('/v1/media/intent', [
        'filename' => $filename, 'size' => $size ?? strlen($bytes), 'mime' => $mime,
    ]);
}

/** Intent, then the browser's PUT (written straight to the staging key), without completing. */
function stageUpload(Tenant $tenant, string $filename, string $bytes, string $mime, ?int $declaredSize = null): string
{
    $id = (string) requestIntent($filename, $bytes, $mime, $declaredSize)->assertCreated()->json('data.media_id');
    putMediaObject($tenant, MediaKeys::staging($id, strtolower(pathinfo($filename, PATHINFO_EXTENSION))), $bytes);

    return $id;
}

/** A ready item with a real object, created through the API. */
function uploadReady(Tenant $tenant, string $filename = 'notes.txt', ?string $bytes = null, string $mime = 'text/plain'): MediaItem
{
    $id = stageUpload($tenant, $filename, $bytes ?? "hello media\n", $mime);
    test()->postJson("/v1/media/{$id}/complete")->assertOk();

    return $tenant->run(fn () => MediaItem::query()->findOrFail($id));
}

function mediaItemIn(Tenant $tenant, string $state = 'ready', array $attributes = []): MediaItem
{
    $factory = MediaItem::factory()->forTenant($tenant);
    $factory = match ($state) {
        'ready' => $factory->ready(),
        'trashed' => $factory->trashed(),
        default => $factory->state(['state' => $state]),
    };

    return $factory->create($attributes);
}

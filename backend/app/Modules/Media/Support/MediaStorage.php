<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Support\Time\Clock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * The only class in the module that knows which filesystem disks hold media
 * (docs/03-architecture/storage.md). Keys are tenant-relative: the filesystem tenancy
 * bootstrapper roots both disks under `tenants/{tenant_id}/`, so the disks are resolved on every
 * call and never cached on this object.
 *
 * `helpdesk.media.disk` is the storage disk (default: the application's default disk).
 * `helpdesk.media.presign_disk` signs browser URLs; when the storage disk is `s3` it defaults to
 * `s3-presign` (signed for the public files host), otherwise to the storage disk itself.
 */
final class MediaStorage
{
    /** Headers a browser refuses to set or that the SDK never signs; they are not sent to the SPA. */
    private const UNSETTABLE_HEADERS = ['host', 'content-length'];

    public function __construct(private readonly Clock $clock) {}

    public function diskName(): string
    {
        return (string) (config('helpdesk.media.disk') ?: config('filesystems.default'));
    }

    public function presignDiskName(): string
    {
        $configured = config('helpdesk.media.presign_disk');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->diskName() === 's3' ? 's3-presign' : $this->diskName();
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * A presigned PUT for one staged object.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function uploadUrl(string $key, string $mime, int $size, int $ttlSeconds = 300): array
    {
        // ContentLength is passed so drivers that can bind it do; SigV4 presigning in the AWS SDK
        // leaves Content-Length unsigned, which is why completion re-verifies the real size.
        $signed = $this->presignDisk()->temporaryUploadUrl(
            $key,
            $this->clock->now()->addSeconds($ttlSeconds),
            ['ContentType' => $mime, 'ContentLength' => $size],
        );

        $headers = ['Content-Type' => $mime];
        foreach ($signed['headers'] ?? [] as $name => $value) {
            if (in_array(strtolower((string) $name), self::UNSETTABLE_HEADERS, true)) {
                continue;
            }
            $headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return ['url' => (string) $signed['url'], 'headers' => $headers];
    }

    public function downloadUrl(string $key, string $filename, int $ttlSeconds = 300): string
    {
        return $this->presignDisk()->temporaryUrl(
            $key,
            $this->clock->now()->addSeconds($ttlSeconds),
            ['ResponseContentDisposition' => MediaFilename::contentDisposition($filename)],
        );
    }

    public function variantUrl(string $key, int $ttlSeconds = 300): string
    {
        return $this->presignDisk()->temporaryUrl(
            $key,
            $this->clock->now()->addSeconds($ttlSeconds),
            ['ResponseContentType' => 'image/webp'],
        );
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function size(string $key): int
    {
        return (int) $this->disk()->size($key);
    }

    /** @return resource|null */
    public function readStream(string $key)
    {
        return $this->disk()->readStream($key);
    }

    public function put(string $key, string $contents, string $mime): bool
    {
        return (bool) $this->disk()->put($key, $contents, ['ContentType' => $mime]);
    }

    public function move(string $from, string $to): bool
    {
        return $this->disk()->move($from, $to);
    }

    /** @param string|list<string> $keys */
    public function delete(string|array $keys): bool
    {
        return $this->disk()->delete($keys);
    }

    /**
     * Staged objects nobody completed (or that a replayed presigned PUT wrote after completion).
     *
     * @return list<string>
     */
    public function staleStagedKeys(int $olderThanTimestamp): array
    {
        $disk = $this->disk();
        $stale = [];
        foreach ($disk->files(MediaKeys::STAGING_PREFIX) as $key) {
            if ($disk->lastModified($key) < $olderThanTimestamp) {
                $stale[] = $key;
            }
        }

        return $stale;
    }

    private function presignDisk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($this->presignDiskName());
    }
}

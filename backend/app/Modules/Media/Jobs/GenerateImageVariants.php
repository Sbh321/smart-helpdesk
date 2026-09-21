<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaStorage;
use App\Support\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

/**
 * WebP `preview` (≤ 1200 px) and `thumb` (≤ 240 px) for a ready image. Runs on the `media` queue;
 * the tenant comes from the queue tenancy bootstrapper (the payload carries the tenant id), so
 * nothing here names a tenant. Variants are a convenience: whatever happens in this job, the
 * item stays ready and downloadable, and `variants.variants_skipped` tells the UI to stop waiting.
 */
final class GenerateImageVariants implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use TenantAwareJob;

    public const QUEUE = 'media';

    /** Longest side per variant; the thumb is derived from the already reduced preview. */
    private const SIZES = ['preview' => 1200, 'thumb' => 240];

    public function __construct(public readonly string $mediaItemId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(MediaStorage $storage): void
    {
        $item = MediaItem::query()->find($this->mediaItemId);
        if ($item === null || $item->state !== 'ready' || ! str_starts_with($item->mime_type, 'image/')) {
            return;
        }
        if (isset($item->variants['thumb'], $item->variants['preview']) || isset($item->variants['variants_skipped'])) {
            return;
        }

        // Decoding costs about four bytes per pixel whatever the file size, so a small, highly
        // compressed file can still exhaust the worker. The stored dimensions decide before any decode.
        $pixels = (int) $item->width * (int) $item->height;
        if ($pixels < 1 || $pixels > (int) config('helpdesk.media.variant_max_pixels', 40_000_000)) {
            $this->skip('pixel_limit');

            return;
        }

        $this->raiseMemoryLimit();
        $path = tempnam(sys_get_temp_dir(), 'media-variant-');
        $stream = $storage->exists($item->storage_key) ? $storage->readStream($item->storage_key) : null;
        if ($path === false || ! is_resource($stream)) {
            throw new RuntimeException("Could not read the original of Media item {$item->id}.");
        }

        $written = [];
        try {
            file_put_contents($path, $stream);
            fclose($stream);

            // Decoded once: scaleDown() reduces this same image to the preview, then to the thumb.
            $image = (new ImageManager(new Driver))->decodePath($path);
            $variants = [];
            foreach (self::SIZES as $name => $side) {
                $image = $image->scaleDown($side, $side);
                $key = MediaKeys::variant($item->id, $name);
                if (! $storage->put($key, (string) $image->encode(new WebpEncoder(quality: 80, strip: true)), 'image/webp')) {
                    throw new RuntimeException("Could not store the {$name} variant of Media item {$item->id}.");
                }
                $written[] = $key;
                $variants[$name] = ['key' => $key, 'width' => $image->width(), 'height' => $image->height()];
            }

            $saved = DB::transaction(function () use ($variants): bool {
                $item = MediaItem::query()->whereKey($this->mediaItemId)->lockForUpdate()->first();
                if ($item === null) {
                    return false;
                }
                $item->forceFill(['variants' => $variants])->save();

                return true;
            });
            if (! $saved) {
                $storage->delete($written);
            }
        } catch (Throwable $exception) {
            if ($written !== []) {
                $storage->delete($written);
            }
            throw $exception;
        } finally {
            @unlink($path);
        }
    }

    /** Called after the last attempt: the item stays usable, the UI stops waiting for a thumbnail. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('media.variants.failed', ['media_item_id' => $this->mediaItemId, 'error' => $exception?->getMessage()]);
        $this->skip('failed');
    }

    /** The pixel cap bounds the decode at roughly 40 MP × 4 B plus the preview; the CLI default of 256 MB is too tight for that. */
    private function raiseMemoryLimit(): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }
    }

    private function skip(string $reason): void
    {
        DB::transaction(function () use ($reason): void {
            $item = MediaItem::query()->whereKey($this->mediaItemId)->lockForUpdate()->first();
            $item?->forceFill(['variants' => ['variants_skipped' => $reason]])->save();
        });
    }
}

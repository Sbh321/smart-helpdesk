<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Domain\FileInspection;
use App\Modules\Media\Exceptions\MediaStateConflict;
use App\Modules\Media\Exceptions\StorageUnavailable;
use App\Modules\Media\Exceptions\UploadRejected;
use App\Modules\Media\Jobs\GenerateImageVariants;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\FileInspector;
use App\Modules\Media\Support\MediaFilename;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a staged upload into a ready Media item in three separate phases:
 *
 *  1. VERIFY, with no database transaction open: the staged object is streamed to a temporary
 *     file (never into memory) and checked for existence, real size, libmagic type, image
 *     dimensions / OOXML entries, and hashed.
 *  2. MOVE the object from `uploads/{id}.{ext}` to `media/{id}/original.{ext}`.
 *  3. COMMIT in one short transaction: lock the item row, flip it to `ready`, and add its bytes to
 *     the used counter with a single atomic UPDATE as the last statement. The `tenant_counters`
 *     row (which ticket numbering locks) is therefore held for one statement and never across
 *     storage I/O.
 *
 * Retries are safe: a ready item is returned as is; a retry after a crash between 2 and 3 finds
 * the object already at its final key and verifies it there. Concurrent calls for one item are
 * kept apart by a short cache lock. A rejected upload is marked `failed` (which releases its
 * reservation, because reservations are the sum of pending items) and its object is deleted.
 */
final class CompleteUpload
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaQuota $quota,
        private readonly FileInspector $inspector,
        private readonly Clock $clock,
    ) {}

    public function __invoke(MediaItem $item): MediaItem
    {
        if ($item->state === 'ready') {
            return $item;
        }

        $lock = Cache::lock("media-complete:{$item->id}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw MediaStateConflict::completionInProgress();
        }

        try {
            $item = MediaItem::query()->findOrFail($item->id);
            if ($item->state === 'ready') {
                return $item;
            }
            if ($item->state !== 'pending') {
                throw MediaStateConflict::state($item->state, ['pending']);
            }

            $extension = MediaFilename::extension($item->storage_key);
            $staged = MediaKeys::staging($item->id, $extension);

            // A retry after a crash between the move and the commit finds the object at its final key.
            $source = match (true) {
                $this->storage->exists($staged) => $staged,
                $this->storage->exists($item->storage_key) => $item->storage_key,
                default => null,
            };
            if ($source === null) {
                throw $this->reject($item, UploadRejected::MISSING_OBJECT, []);
            }

            $size = $this->storage->size($source);
            if ($size !== $item->size_bytes || $size > AllowedMedia::maxBytes()) {
                throw $this->reject($item, UploadRejected::SIZE_MISMATCH, [$source]);
            }

            $inspection = $this->inspect($source, $extension, $size);
            if (! $inspection->accepted()) {
                throw $this->reject($item, (string) $inspection->rejection, [$source]);
            }

            if ($source === $staged && ! $this->storage->move($staged, $item->storage_key)) {
                throw StorageUnavailable::during('move');
            }

            return $this->commit($item->id, $inspection);
        } finally {
            $lock->release();
        }
    }

    private function inspect(string $key, string $extension, int $expectedSize): FileInspection
    {
        $path = tempnam(sys_get_temp_dir(), 'media-');
        if ($path === false) {
            throw StorageUnavailable::during('buffer');
        }

        try {
            $stream = $this->storage->readStream($key);
            $target = fopen($path, 'wb');
            if (! is_resource($stream) || $target === false) {
                throw StorageUnavailable::during('read');
            }
            try {
                // One byte more than expected is enough to notice an object that grew after size().
                $copied = stream_copy_to_stream($stream, $target, $expectedSize + 1);
            } finally {
                fclose($stream);
                fclose($target);
            }
            if ($copied !== $expectedSize) {
                return new FileInspection(UploadRejected::SIZE_MISMATCH);
            }

            return $this->inspector->inspect($path, $extension);
        } finally {
            @unlink($path);
        }
    }

    private function commit(string $id, FileInspection $inspection): MediaItem
    {
        return DB::transaction(function () use ($id, $inspection): MediaItem {
            $item = MediaItem::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($item->state === 'ready') {
                return $item;
            }
            if ($item->state !== 'pending') {
                throw MediaStateConflict::state($item->state, ['pending']);
            }

            $item->forceFill([
                'checksum_sha256' => $inspection->checksumSha256,
                'width' => $inspection->width,
                'height' => $inspection->height,
                'state' => 'ready',
                'completed_at' => $this->clock->now(),
            ])->save();

            if (AllowedMedia::isImage(MediaFilename::extension($item->storage_key))) {
                DB::afterCommit(fn () => GenerateImageVariants::dispatch($item->id));
            }

            // Last statement on purpose: the counter row lock lives until COMMIT, which is next.
            $this->quota->add($item->tenant_id, $item->size_bytes);

            return $item;
        });
    }

    /** @param list<string> $keys objects to remove */
    private function reject(MediaItem $item, string $reason, array $keys): UploadRejected
    {
        // Leaving `pending` releases the reservation; the guard keeps a concurrent success intact.
        MediaItem::query()->whereKey($item->id)->where('state', 'pending')->update(['state' => 'failed']);
        if ($keys !== []) {
            $this->storage->delete($keys);
        }

        return UploadRejected::because($reason);
    }
}

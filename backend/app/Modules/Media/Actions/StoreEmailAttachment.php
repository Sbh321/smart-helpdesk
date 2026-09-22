<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\QuotaExceeded;
use App\Modules\Media\Exceptions\StorageUnavailable;
use App\Modules\Media\Jobs\GenerateImageVariants;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\FileInspector;
use App\Modules\Media\Support\MediaFilename;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores one attachment of an inbound email as a ready Media item in the `Email` system folder
 * (docs/04-domain/media.md §Rules, docs/04-domain/email.md). Nobody uploaded it, so the upload
 * rules apply without trusting anything the sender declared: the extension must be allow-listed,
 * the size within the upload limit, the bytes must sniff as that type (the same `FileInspector` as an
 * upload completion), and the quota is reserved under the tenant lock before the object is written.
 * Runs inside the workspace. Returns null with a reason instead of throwing for a file that is
 * refused, so one bad attachment never loses the message.
 */
final readonly class StoreEmailAttachment
{
    /** Refusal reasons recorded on the inbound email. */
    public const string TYPE_NOT_ALLOWED = 'type_not_allowed';

    public const string TOO_LARGE = 'too_large';

    public const string EMPTY = 'empty';

    public const string QUOTA_EXCEEDED = 'quota_exceeded';

    public const string STORAGE_FAILED = 'storage_failed';

    public function __construct(
        private MediaStorage $storage,
        private MediaQuota $quota,
        private FileInspector $inspector,
        private Clock $clock,
    ) {}

    /**
     * @return array{item: MediaItem|null, reason: string|null}
     */
    public function __invoke(string $filename, string $contents): array
    {
        $name = MediaFilename::sanitise($filename);
        $extension = AllowedMedia::extensionFor($name);
        $size = strlen($contents);

        if ($extension === null) {
            return ['item' => null, 'reason' => self::TYPE_NOT_ALLOWED];
        }
        if ($size < 1) {
            return ['item' => null, 'reason' => self::EMPTY];
        }
        if ($size > AllowedMedia::maxBytes()) {
            return ['item' => null, 'reason' => self::TOO_LARGE];
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'inbound-attachment-');
        try {
            file_put_contents($path, $contents);
            $inspection = $this->inspector->inspect($path, $extension);
            if (! $inspection->accepted()) {
                return ['item' => null, 'reason' => self::TYPE_NOT_ALLOWED];
            }

            try {
                $item = $this->reserve($name, $extension, $size, (string) $inspection->checksumSha256, $inspection->width, $inspection->height);
            } catch (QuotaExceeded) {
                return ['item' => null, 'reason' => self::QUOTA_EXCEEDED];
            }

            try {
                if (! $this->storage->put($item->storage_key, $contents, $item->mime_type)) {
                    throw new RuntimeException('The object store refused the file.');
                }
            } catch (Throwable $exception) {
                // Leaving `pending` releases the reservation.
                MediaItem::query()->whereKey($item->id)->where('state', 'pending')->update(['state' => 'failed']);
                report(StorageUnavailable::during('store', $exception));

                return ['item' => null, 'reason' => self::STORAGE_FAILED];
            }

            return ['item' => $this->commit($item->id, $extension), 'reason' => null];
        } finally {
            @unlink($path);
        }
    }

    private function reserve(string $name, string $extension, int $size, string $checksum, ?int $width, ?int $height): MediaItem
    {
        $tenantId = (string) tenant()?->getTenantKey();

        return DB::transaction(function () use ($tenantId, $name, $extension, $size, $checksum, $width, $height): MediaItem {
            $this->quota->lock($tenantId);
            $usage = $this->quota->usage($tenantId);
            if ($usage->used_bytes + $usage->pending_bytes + $size > $usage->quota_bytes) {
                throw new QuotaExceeded;
            }

            $folder = MediaFolder::query()->firstOrCreate(['system_key' => 'email'], ['name' => 'Email', 'parent_id' => null]);
            $id = (string) Str::uuid7();
            $now = $this->clock->now();

            $item = new MediaItem;
            $item->id = $id;
            $item->forceFill([
                'folder_id' => $folder->id,
                'name' => $name,
                'storage_key' => MediaKeys::original($id, $extension),
                'mime_type' => AllowedMedia::mimeFor($extension),
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'width' => $width,
                'height' => $height,
                'variants' => [],
                'source' => 'email',
                'state' => 'pending',
                'uploaded_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $item->save();

            return $item;
        });
    }

    private function commit(string $id, string $extension): MediaItem
    {
        return DB::transaction(function () use ($id, $extension): MediaItem {
            $item = MediaItem::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $item->forceFill(['state' => 'ready', 'completed_at' => $this->clock->now()])->save();

            if (AllowedMedia::isImage($extension)) {
                DB::afterCommit(fn () => GenerateImageVariants::dispatch($item->id));
            }

            // Last statement on purpose: the counter row lock lives until COMMIT, which is next.
            $this->quota->add($item->tenant_id, $item->size_bytes);

            return $item;
        });
    }
}

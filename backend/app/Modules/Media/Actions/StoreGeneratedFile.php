<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\QuotaExceeded;
use App\Modules\Media\Exceptions\StorageUnavailable;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\MediaFilename;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Stores a file the server generated itself (a report export) as a ready Media item in a system
 * folder (docs/04-domain/media.md §System folders). It follows the upload rules without the browser
 * round trip: the quota is reserved under the tenant lock by a `pending` item, the object is streamed
 * to its media key, and one short transaction flips the item to `ready` and adds its bytes, the
 * counter update being the last statement. The file is ours, so it is not sniffed; its type comes
 * from the allow-listed extension. Runs inside the workspace.
 */
final readonly class StoreGeneratedFile
{
    /** System folders that receive generated files: key => display name. */
    public const array FOLDERS = ['reports' => 'Reports'];

    public function __construct(
        private MediaStorage $storage,
        private MediaQuota $quota,
        private Clock $clock,
    ) {}

    /**
     * @param  string  $path  a local file, left in place for the caller to delete
     *
     * @throws QuotaExceeded when the file does not fit in the workspace quota
     * @throws StorageUnavailable when the object store refuses the write
     */
    public function __invoke(string $path, string $filename, string $ownerUserId, string $folderKey = 'reports'): MediaItem
    {
        $name = MediaFilename::sanitise($filename);
        $extension = MediaFilename::extension($name);
        $size = (int) filesize($path);
        if (! isset(self::FOLDERS[$folderKey])) {
            throw new InvalidArgumentException("Unknown system folder [{$folderKey}].");
        }
        if ($size < 1 || $size > AllowedMedia::maxBytes()) {
            throw new InvalidArgumentException('Generated files must be between 1 byte and the upload limit.');
        }

        $tenantId = (string) tenant()?->getTenantKey();
        $mime = AllowedMedia::mimeFor($extension);

        $item = DB::transaction(function () use ($tenantId, $name, $size, $mime, $extension, $ownerUserId, $folderKey, $path): MediaItem {
            $this->quota->lock($tenantId);
            $usage = $this->quota->usage($tenantId);
            if ($usage->used_bytes + $usage->pending_bytes + $size > $usage->quota_bytes) {
                throw new QuotaExceeded;
            }

            $folder = MediaFolder::query()->firstOrCreate(
                ['system_key' => $folderKey],
                ['name' => self::FOLDERS[$folderKey], 'parent_id' => null],
            );
            $id = (string) Str::uuid7();
            $now = $this->clock->now();

            $item = new MediaItem;
            $item->id = $id;
            $item->forceFill([
                'folder_id' => $folder->id,
                'name' => $name,
                'storage_key' => MediaKeys::original($id, $extension),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum_sha256' => hash_file('sha256', $path),
                'variants' => [],
                'source' => 'system',
                'state' => 'pending',
                'uploaded_by_user_id' => $ownerUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $item->save();

            return $item;
        });

        $stream = fopen($path, 'rb');
        try {
            if ($stream === false || ! $this->storage->putStream($item->storage_key, $stream, $mime)) {
                throw new RuntimeException('The object store refused the file.');
            }
        } catch (Throwable $exception) {
            // Leaving `pending` releases the reservation.
            MediaItem::query()->whereKey($item->id)->where('state', 'pending')->update(['state' => 'failed']);
            throw StorageUnavailable::during('store', $exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return DB::transaction(function () use ($item): MediaItem {
            $item = MediaItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $item->forceFill(['state' => 'ready', 'completed_at' => $this->clock->now()])->save();

            // Last statement on purpose: the counter row lock lives until COMMIT, which is next.
            $this->quota->add($item->tenant_id, $item->size_bytes);

            return $item;
        });
    }
}

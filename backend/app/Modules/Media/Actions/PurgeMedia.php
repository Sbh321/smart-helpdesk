<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\MediaInUse;
use App\Modules\Media\Exceptions\MediaStateConflict;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permanently removes one Media item. Rows go first, inside the transaction; the objects are
 * deleted only after COMMIT, so a rollback can never leave a row that points at nothing. An
 * object that survives a failed delete is an orphan without a row, which is harmless and logged.
 */
final class PurgeMedia
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaQuota $quota,
    ) {}

    /**
     * @return bool false when the item no longer exists
     *
     * @throws MediaInUse when the item is still linked
     * @throws MediaStateConflict when `$onlyInState` is given and the locked row is in another state
     */
    public function __invoke(string $id, ?string $onlyInState = null): bool
    {
        return DB::transaction(function () use ($id, $onlyInState): bool {
            // AttachMedia locks the same row, so a link cannot appear between the check and the delete.
            $item = MediaItem::query()->whereKey($id)->lockForUpdate()->first();
            if ($item === null) {
                return false;
            }
            if ($onlyInState !== null && $item->state !== $onlyInState) {
                throw MediaStateConflict::state($item->state, [$onlyInState]);
            }
            $links = $item->links()->count();
            if ($links > 0) {
                throw MediaInUse::item($links);
            }

            $keys = [$item->storage_key, MediaKeys::stagingFor($item->id, $item->storage_key)];
            foreach (MediaKeys::VARIANTS as $name) {
                $key = $item->variants[$name]['key'] ?? null;
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
            $countsTowardsQuota = in_array($item->state, ['ready', 'trashed'], true);
            [$tenantId, $bytes] = [$item->tenant_id, $item->size_bytes];

            $item->tags()->detach();
            $item->delete();

            DB::afterCommit(function () use ($keys, $id): void {
                if (! $this->storage->delete($keys)) {
                    Log::warning('media.purge.orphaned_objects', ['media_item_id' => $id, 'keys' => $keys]);
                }
            });

            if ($countsTowardsQuota) {
                // Last statement: the counter row is shared with ticket numbering.
                $this->quota->release($tenantId, $bytes);
            }

            return true;
        });
    }
}

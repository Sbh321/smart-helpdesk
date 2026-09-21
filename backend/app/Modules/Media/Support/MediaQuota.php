<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Domain\MediaUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workspace storage accounting (docs/04-domain/media.md §Quota).
 *
 * Used bytes live on `tenant_counters.storage_used_bytes`, the row that ticket numbering locks.
 * So this class never SELECT … FOR UPDATEs that row: reservations are serialised with a
 * transaction-scoped advisory lock instead, and the counter is only ever touched by one atomic
 * `UPDATE … SET used = used ± n`, issued as the LAST statement of a short transaction that does no
 * storage I/O. Reserved bytes are not stored: they are the sum of `pending` items, so a failed or
 * expired upload releases its reservation by leaving that state.
 */
final class MediaQuota
{
    /** Serialises quota decisions of one tenant; call inside a transaction. */
    public function lock(string $tenantId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$tenantId, 'media-quota']);
    }

    /** One statement, so used and reserved bytes come from the same snapshot. */
    public function usage(string $tenantId): MediaUsage
    {
        $row = DB::selectOne(
            "SELECT t.storage_quota_bytes AS quota,
                    COALESCE((SELECT c.storage_used_bytes FROM tenant_counters c WHERE c.tenant_id = t.id), 0) AS used,
                    COALESCE((SELECT SUM(m.size_bytes) FROM media_items m WHERE m.tenant_id = t.id AND m.state = 'pending'), 0) AS pending
             FROM tenants t WHERE t.id = ?",
            [$tenantId],
        );

        return new MediaUsage((int) ($row->used ?? 0), (int) ($row->quota ?? 0), (int) ($row->pending ?? 0));
    }

    public function add(string $tenantId, int $bytes): void
    {
        // An upsert, like ticket numbering: a workspace created before its counter row still counts.
        DB::statement(
            'INSERT INTO tenant_counters (tenant_id, storage_used_bytes) VALUES (?, ?)
             ON CONFLICT (tenant_id) DO UPDATE SET storage_used_bytes = tenant_counters.storage_used_bytes + EXCLUDED.storage_used_bytes',
            [$tenantId, $bytes],
        );
    }

    public function release(string $tenantId, int $bytes): void
    {
        // PostgreSQL 18 `RETURNING old.*`: the value this very UPDATE replaced. (A `SELECT … FOR UPDATE`
        // CTE read in RETURNING skips the row the statement has just updated and yields NULL.)
        $row = DB::selectOne(
            'UPDATE tenant_counters SET storage_used_bytes = GREATEST(0, storage_used_bytes - ?)
             WHERE tenant_id = ? RETURNING old.storage_used_bytes AS previous_bytes',
            [$bytes, $tenantId],
        );

        if ($row !== null && (int) $row->previous_bytes < $bytes) {
            Log::warning('media.quota.clamped', [
                'tenant_id' => $tenantId, 'released_bytes' => $bytes, 'previous_bytes' => (int) $row->previous_bytes,
            ]);
        }
    }
}

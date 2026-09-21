<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Exceptions\MediaStateConflict;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Links a ready Media item to a Ticket (≤ 50) or a Comment (≤ 10) as an attachment. */
final class AttachMedia
{
    private const LIMITS = ['ticket' => 50, 'ticket_comment' => 10];

    public function __construct(private readonly Clock $clock) {}

    public function __invoke(MediaItem $item, string $subjectType, string $subjectId, string $role = 'attachment'): Mediable
    {
        if (! isset(self::LIMITS[$subjectType]) || $role !== 'attachment') {
            throw new InvalidArgumentException("Media cannot be attached to [{$subjectType}] as [{$role}].");
        }

        return DB::transaction(function () use ($item, $subjectType, $subjectId, $role): Mediable {
            // The tenant scope makes a foreign id a 404; the row lock keeps trash and purge out
            // until the link exists.
            $item = MediaItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->state !== 'ready') {
                throw MediaStateConflict::state($item->state, ['ready']);
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
                $item->tenant_id, "{$subjectType}:{$subjectId}",
            ]);
            $existing = Mediable::query()
                ->where('mediable_type', $subjectType)
                ->where('mediable_id', $subjectId)
                ->where('role', $role)
                ->get();

            $linked = $existing->firstWhere('media_item_id', $item->id);
            if ($linked !== null) {
                return $linked;
            }
            $limit = self::LIMITS[$subjectType];
            if ($existing->count() >= $limit) {
                throw ValidationException::withMessages(['media_ids' => "At most {$limit} attachments are allowed."]);
            }

            return Mediable::query()->create([
                'media_item_id' => $item->id,
                'mediable_type' => $subjectType,
                'mediable_id' => $subjectId,
                'role' => $role,
                'created_at' => $this->clock->now(),
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Models\User;
use App\Modules\Media\Domain\MediaTicketUse;
use App\Modules\Media\Models\MediaItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Where Media items are used. Tickets depends on Media, never the other way round
 * (docs/03-architecture/backend.md §Dependency rules), so this class reads the `tickets` and
 * `ticket_comments` tables directly instead of importing the Tickets models. Every query names
 * the tenant explicitly because the query builder has no tenant scope.
 */
final class MediaUses
{
    /**
     * Ticket numbers per Media item, in one query for the whole page.
     *
     * @param  list<string>  $itemIds
     * @return array<string, list<MediaTicketUse>>
     */
    public function ticketsFor(string $tenantId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $direct = $this->links($tenantId, $itemIds)
            ->where('mediables.mediable_type', 'ticket')
            ->join('tickets', fn (JoinClause $join) => $join
                ->on('tickets.id', '=', 'mediables.mediable_id')
                ->on('tickets.tenant_id', '=', 'mediables.tenant_id'))
            ->select(['mediables.media_item_id', 'tickets.number']);

        $throughComments = $this->links($tenantId, $itemIds)
            ->where('mediables.mediable_type', 'ticket_comment')
            ->join('ticket_comments', fn (JoinClause $join) => $join
                ->on('ticket_comments.id', '=', 'mediables.mediable_id')
                ->on('ticket_comments.tenant_id', '=', 'mediables.tenant_id'))
            ->join('tickets', fn (JoinClause $join) => $join
                ->on('tickets.id', '=', 'ticket_comments.ticket_id')
                ->on('tickets.tenant_id', '=', 'ticket_comments.tenant_id'))
            ->select(['mediables.media_item_id', 'tickets.number']);

        $uses = [];
        foreach ($direct->union($throughComments)->orderBy('number')->get() as $row) {
            $uses[(string) $row->media_item_id][] = new MediaTicketUse((int) $row->number);
        }

        return $uses;
    }

    /**
     * Download policy (docs/03-architecture/storage.md §Download flow), on top of tenant scope and
     * `media.view`: a library file (no links, or linked to something that is not a ticket) is open
     * to every viewer; a file that only lives on tickets needs `tickets.view`, and one that only
     * lives on internal notes needs `comments.internal` as well. The uploader can always read
     * their own file back. A file the server generated (`source = system`: a report export, stored
     * in the `Reports` folder) is readable by its requester (the uploader) only, wherever it is
     * moved and whatever the reader's other permissions.
     */
    public function canDownload(User $user, MediaItem $item): bool
    {
        if ($item->uploaded_by_user_id === $user->id) {
            return true;
        }

        if ($item->source === 'system') {
            return false;
        }

        $links = $this->links($item->tenant_id, [$item->id])
            ->leftJoin('ticket_comments', fn (JoinClause $join) => $join
                ->on('ticket_comments.id', '=', 'mediables.mediable_id')
                ->on('ticket_comments.tenant_id', '=', 'mediables.tenant_id')
                ->where('mediables.mediable_type', '=', 'ticket_comment'))
            ->get(['mediables.mediable_type', 'ticket_comments.visibility']);

        if ($links->isEmpty()) {
            return true;
        }

        $canSeeTickets = $user->can('tickets.view');
        $canSeeInternal = $canSeeTickets && $user->can('comments.internal');

        foreach ($links as $link) {
            $visible = match ($link->mediable_type) {
                'ticket' => $canSeeTickets,
                // A comment that no longer exists grants nothing.
                'ticket_comment' => $link->visibility === 'public' ? $canSeeTickets : ($link->visibility === 'internal' && $canSeeInternal),
                default => true,
            };
            if ($visible) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $itemIds */
    private function links(string $tenantId, array $itemIds): Builder
    {
        return DB::table('mediables')
            ->where('mediables.tenant_id', $tenantId)
            ->whereIn('mediables.media_item_id', $itemIds);
    }
}

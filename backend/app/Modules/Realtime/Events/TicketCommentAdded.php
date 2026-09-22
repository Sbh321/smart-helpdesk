<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Events;

use App\Modules\Realtime\Support\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * `comment.added` on the ticket's channel for a public reply, on the ticket's internal channel for an
 * internal note (docs/03-architecture/realtime.md §Channels). Ids only.
 */
final readonly class TicketCommentAdded implements ShouldBroadcastNow
{
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $commentId,
        public string $visibility,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->visibility === 'internal'
            ? Channels::ticketInternal($this->tenantId, $this->ticketId)
            : Channels::ticket($this->tenantId, $this->ticketId))];
    }

    public function broadcastAs(): string
    {
        return 'comment.added';
    }

    /** @return array{ticket_id: string, comment_id: string, visibility: string} */
    public function broadcastWith(): array
    {
        return ['ticket_id' => $this->ticketId, 'comment_id' => $this->commentId, 'visibility' => $this->visibility];
    }
}

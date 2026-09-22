<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Events;

use App\Modules\Realtime\Listeners\BroadcastTicketActivity;
use App\Modules\Realtime\Support\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something about a ticket changed (docs/03-architecture/realtime.md §Events). Sent on the workspace
 * ticket channel (lists) and on the ticket's own channel (the open ticket). The payload names the
 * ticket and what changed, never its content; the SPA refetches through the API.
 *
 * Broadcast "now" because it is raised by the queued {@see BroadcastTicketActivity}.
 */
final readonly class TicketActivity implements ShouldBroadcastNow
{
    public const array NAMES = ['ticket.created', 'ticket.updated', 'ticket.assigned', 'ticket.status_changed', 'ticket.priority_changed'];

    /**
     * @param  value-of<self::NAMES>  $name
     * @param  array<string, string|list<string>|null>  $details  ids, status or priority codes, field names
     */
    public function __construct(
        public string $tenantId,
        public string $ticketId,
        public string $name,
        public array $details = [],
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Channels::tickets($this->tenantId)),
            new PrivateChannel(Channels::ticket($this->tenantId, $this->ticketId)),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, string|list<string>|null> */
    public function broadcastWith(): array
    {
        return ['ticket_id' => $this->ticketId, ...$this->details];
    }
}

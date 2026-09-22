<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Listeners;

use App\Modules\Realtime\Events\TicketActivity;
use App\Modules\Realtime\Events\TicketCommentAdded;
use App\Modules\Realtime\Support\RealtimeSwitch;
use App\Modules\Realtime\Support\SafeBroadcast;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Tickets\Events\CommentAdded;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Events\TicketCreated;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Events\TicketUpdated;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Turns ticket domain events into broadcasts (docs/03-architecture/realtime.md §Server).
 *
 * Queued after commit on `broadcasts` (its own Horizon supervisor), so a rolled-back change is never
 * announced and the request never waits for Reverb. Queued only when realtime is on
 * ({@see RealtimeSwitch}). The worker runs inside the workspace the event came from (stancl's queue
 * bootstrapper); the channel tenant is the event's own and must equal that context.
 *
 * | Domain event | Broadcast |
 * |---|---|
 * | `TicketCreated` (hook, queued after its commit) | `ticket.created` |
 * | `TicketUpdated` | `ticket.updated` with the changed field names |
 * | `TicketAssigned` | `ticket.assigned` with the agent id |
 * | `TicketStatusChanged` | `ticket.status_changed` with from and to |
 * | `PriorityChanged` | `ticket.priority_changed` with from and to |
 * | `SlaWarning`, `SlaBreached` | `ticket.updated` with `fields: [sla]` |
 * | `CommentAdded` | `comment.added` (public or internal channel) |
 */
final class BroadcastTicketActivity implements ShouldQueueAfterCommit
{
    /** One attempt: a late retry would only announce what the next poll already showed. */
    public int $tries = 1;

    public function __construct(private readonly RealtimeSwitch $switch) {}

    /** Horizon's blocking `redis-broadcasts` connection in production and development; tests keep theirs. */
    public function viaConnection(): string
    {
        $default = (string) config('queue.default');

        return $default === 'redis' ? 'redis-broadcasts' : $default;
    }

    public function viaQueue(): string
    {
        return 'broadcasts';
    }

    public function shouldQueue(object $event): bool
    {
        return $this->switch->enabled();
    }

    public function created(TicketCreated $event): void
    {
        $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.created'));
    }

    public function updated(TicketUpdated $event): void
    {
        $fields = array_map(strval(...), array_keys($event->changes));

        if ($fields !== []) {
            $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.updated', ['fields' => $fields]));
        }
    }

    public function assigned(TicketAssigned $event): void
    {
        $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.assigned', ['agent_id' => $event->agentId]));
    }

    public function statusChanged(TicketStatusChanged $event): void
    {
        $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.status_changed', ['from' => $event->from, 'to' => $event->to]));
    }

    public function priorityChanged(PriorityChanged $event): void
    {
        $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.priority_changed', ['from' => $event->from, 'to' => $event->to]));
    }

    public function slaChanged(SlaWarning|SlaBreached $event): void
    {
        $this->send($event->tenantId, new TicketActivity($event->tenantId, $event->ticketId, 'ticket.updated', ['fields' => ['sla']]));
    }

    public function commented(CommentAdded $event): void
    {
        $this->send($event->tenantId, new TicketCommentAdded($event->tenantId, $event->ticketId, $event->commentId, $event->visibility));
    }

    private function send(string $tenantId, object $broadcast): void
    {
        if (tenant('id') !== $tenantId) {
            Log::warning('realtime.tenant_mismatch', ['event_tenant' => $tenantId, 'context_tenant' => tenant('id')]);

            return;
        }

        SafeBroadcast::send($broadcast);
    }
}

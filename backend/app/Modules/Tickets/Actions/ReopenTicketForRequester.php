<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Modules\Tickets\Queries\TicketTransitionRules;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a resolved or closed ticket because its requester wrote again (inbound email, M3-19):
 * the same writes as a manual reopen in `TransitionTicket`, with actor `system` and no permission
 * check, but only inside the reopen window and never for a ticket closed as a duplicate
 * (`TicketTransitionRules::canReopen`). An active ticket is left alone.
 */
final readonly class ReopenTicketForRequester
{
    public function __construct(private Clock $clock, private TicketTransitionRules $rules) {}

    /**
     * @return bool whether the ticket was reopened
     */
    public function __invoke(Ticket $ticket, string $note = 'Requester replied by email'): bool
    {
        return DB::transaction(function () use ($ticket, $note): bool {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from->isActive() || ! $this->rules->canReopen($locked)) {
                return false;
            }

            $now = $this->clock->now();
            $locked->status = $from->transitionTo(TicketStatus::InProgress);
            $locked->resolved_at = null;
            $locked->closed_at = null;
            $locked->pending_since = null;
            $locked->reopen_count = (int) $locked->reopen_count + 1;
            $locked->version = (int) $locked->version + 1;
            $locked->updated_at = $now;
            $locked->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'reopened',
                'actor_type' => 'system',
                'actor_id' => null,
                'old_values' => ['status' => $from->value],
                'new_values' => [
                    'status' => TicketStatus::InProgress->value,
                    'resolved_at' => null,
                    'closed_at' => null,
                    'reopen_count' => $locked->reopen_count,
                ],
                'note' => $note,
                'created_at' => $now,
            ]);

            event(new TicketLifecycleChanged($locked->tenant_id, $locked->id, $from->value, TicketStatus::InProgress->value));
            event(new TicketStatusChanged($locked->tenant_id, $locked->id, null, $from->value, TicketStatus::InProgress->value));

            return true;
        });
    }

    /** Whether a reply now would reopen the ticket (or finds it active); false means it stays finished. */
    public function accepts(Ticket $ticket): bool
    {
        return $ticket->status->isActive() || $this->rules->canReopen($ticket);
    }
}

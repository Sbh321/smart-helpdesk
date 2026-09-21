<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Modules\Automation\Domain\Exceptions\TicketNotAssigned;
use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Automation\Support\AgentWorkloadCounter;
use App\Modules\Tickets\Domain\Exceptions\InvalidTransition;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Takes the agent off a ticket and returns it to the open queue; the team stays. Assigned and
 * in-progress tickets go back to `open` through the transition table. A pending ticket has no
 * edge to `open` (docs/04-domain/tickets.md), so it is refused with `invalid_transition`:
 * reassign it instead.
 */
final readonly class UnassignTicket
{
    public function __construct(private AgentWorkloadCounter $counter, private Clock $clock) {}

    /**
     * @throws TicketNotAssigned
     * @throws InvalidTransition
     */
    public function __invoke(Ticket $ticket, string $actorId): Ticket
    {
        return DB::transaction(function () use ($ticket, $actorId): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->assigned_agent_id;
            if ($previous === null) {
                throw TicketNotAssigned::for($locked->id, $locked->status->value);
            }

            $from = $locked->status;
            $now = $this->clock->now();
            $locked->status = $from->transitionTo(TicketStatus::Open);
            $locked->assigned_agent_id = null;
            $locked->version++;
            $locked->updated_at = $now;
            $locked->save();

            if (AgentWorkload::counts($from)) {
                $this->counter->decrement($previous);
            }

            TicketAssignment::query()->create([
                'ticket_id' => $locked->id,
                'team_id' => $locked->team_id,
                'agent_profile_id' => null,
                'previous_agent_profile_id' => $previous,
                'reason' => 'unassign',
                'assigned_by_user_id' => $actorId,
                'explanation' => ['outcome' => 'unassigned', 'selection' => 'manual', 'ticket_id' => $locked->id, 'agent_id' => null],
                'settings_version' => null,
                'created_at' => $now,
            ]);
            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'unassigned',
                'actor_type' => 'user',
                'actor_id' => $actorId,
                'old_values' => ['agent_id' => $previous, 'status' => $from->value],
                'new_values' => ['agent_id' => null, 'status' => $locked->status->value],
                'note' => null,
                'created_at' => $now,
            ]);

            event(new TicketStatusChanged($locked->tenant_id, $locked->id, $actorId, $from->value, $locked->status->value));

            return $locked;
        });
    }
}

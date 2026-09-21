<?php

declare(strict_types=1);

namespace App\Modules\Automation\Listeners;

use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Automation\Support\AgentWorkloadCounter;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketLifecycleChanged;
use App\Modules\Tickets\Models\Ticket;

/**
 * Keeps the stored agent workload in step with status changes (resolve, close, reopen), inside
 * the ticket's transaction. Agent changes are handled by AssignTicket and UnassignTicket.
 */
final readonly class AdjustWorkloadOnTicketLifecycle
{
    public function __construct(private AgentWorkloadCounter $counter) {}

    public function handle(TicketLifecycleChanged $event): void
    {
        $counted = AgentWorkload::counts(TicketStatus::from($event->from));
        $counts = AgentWorkload::counts(TicketStatus::from($event->to));
        if ($counted === $counts) {
            return;
        }

        $agentId = Ticket::query()->whereKey($event->ticketId)->value('assigned_agent_id');
        if (! is_string($agentId)) {
            return;
        }

        $counts ? $this->counter->increment($agentId) : $this->counter->decrement($agentId);
    }
}

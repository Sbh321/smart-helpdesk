<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Automation\Domain\Exceptions\PriorityOverrideNotAllowed;
use App\Modules\Sla\Actions\RecomputeTicketSla;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Events\PriorityChanged;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * A manager's level always wins over the computed one (docs/05-algorithms/priority-scoring.md);
 * `null` clears the override. Writes ticket history and an audit entry, recomputes SLA deadlines
 * when the effective level moves, and refuses resolved or closed tickets.
 */
final readonly class OverrideTicketPriority
{
    public function __construct(private RecomputeTicketSla $sla, private Clock $clock) {}

    public function __invoke(Ticket $ticket, ?Priority $level, ?string $reason, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $level, $reason, $actor): Ticket {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->isActive()) {
                throw PriorityOverrideNotAllowed::forStatus($locked->id, $locked->status->value);
            }
            $now = $this->clock->now();
            $previous = $locked->effectivePriority();
            $previousOverride = $locked->priority_override_level;
            $explanation = $locked->priority_explanation;
            $explanation['effective_level'] = ($level ?? $locked->priority_level)->value;
            $explanation['manual_override'] = $level !== null;
            $locked->forceFill([
                'priority_override_level' => $level,
                'priority_override_reason' => $reason,
                'priority_override_by' => $level === null ? null : $actor->id,
                'priority_explanation' => $explanation,
                'version' => $locked->version + 1,
                'updated_at' => $now,
            ])->save();
            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'type' => 'priority_overridden',
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'old_values' => ['priority' => $previous->value],
                'new_values' => ['priority' => $locked->effectivePriority()->value, 'reason' => $reason],
                'note' => $reason,
                'created_at' => $now,
            ]);
            Audit::record('ticket.priority_overridden', $locked, [
                'old' => ['override_level' => $previousOverride?->value, 'effective_level' => $previous->value],
                'new' => ['override_level' => $level?->value, 'effective_level' => $locked->effectivePriority()->value, 'reason' => $reason],
            ]);
            if ($previous !== $locked->effectivePriority()) {
                ($this->sla)($locked);
                event(new PriorityChanged($locked->tenant_id, $locked->id, $previous->value, $locked->effectivePriority()->value));
            }

            return $locked;
        });
    }
}

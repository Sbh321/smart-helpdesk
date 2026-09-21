<?php

declare(strict_types=1);

namespace App\Modules\Sla\Support;

use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarning;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;

final readonly class SlaTimerStore
{
    public function __construct(private SlaCalendarResolver $calendars) {}

    public function start(
        Ticket $ticket,
        SlaPolicy $policy,
        TimerKind $kind,
        int $minutes,
        int $cycle,
        SlaStrategy $strategy,
    ): TicketSlaTimer {
        // Deadlines are computed with the calendar the row stores, never with one passed in.
        $outcome = $strategy->start($kind, $minutes * 60, $this->calendars->forId($policy->calendar_id));
        $timer = $outcome->timer;
        $stored = TicketSlaTimer::query()->create([
            'ticket_id' => $ticket->id,
            'policy_id' => $policy->id,
            'policy_version' => $policy->version,
            'warning_fraction' => $policy->warning_fraction,
            'kind' => $kind,
            'cycle' => $cycle,
            'state' => $timer->state,
            'paused_from_state' => null,
            'target_minutes' => $minutes,
            'started_at' => $timer->startedAt,
            'warning_at' => $timer->warningAt,
            'due_at' => $timer->dueAt,
            'paused_at' => null,
            'paused_total_seconds' => 0,
            'warned_at' => null,
            'breached_at' => null,
            'met_at' => null,
            'cancelled_at' => null,
            'calendar_id' => $policy->calendar_id,
            'strategy' => $outcome->strategy,
            'strategy_version' => $outcome->strategyVersion,
        ]);

        foreach ($outcome->events as $event) {
            $stored->events()->create([
                'ticket_id' => $ticket->id,
                'type' => $event->type->value,
                'payload' => $event->toArray(),
                'created_at' => $event->at,
            ]);
        }

        return $stored;
    }

    public function read(TicketSlaTimer $row): TimerData
    {
        return new TimerData(
            kind: $row->kind,
            state: $row->state,
            targetSeconds: $row->target_minutes * 60,
            startedAt: $row->started_at,
            warningAt: $row->warning_at,
            dueAt: $row->due_at,
            pausedAt: $row->paused_at,
            pausedTotalSeconds: $row->paused_total_seconds,
            warnedAt: $row->warned_at,
            breachedAt: $row->breached_at,
            metAt: $row->met_at,
            cancelledAt: $row->cancelled_at,
        );
    }

    public function store(TicketSlaTimer $row, TimerOutcome $outcome, ?TimerState $pausedFromState = null): void
    {
        $timer = $outcome->timer;
        $row->forceFill([
            'state' => $timer->state,
            'target_minutes' => intdiv($timer->targetSeconds, 60),
            'started_at' => $timer->startedAt,
            'warning_at' => $timer->warningAt,
            'due_at' => $timer->dueAt,
            'paused_at' => $timer->pausedAt,
            'paused_from_state' => $pausedFromState,
            'paused_total_seconds' => $timer->pausedTotalSeconds,
            'warned_at' => $timer->warnedAt,
            'breached_at' => $timer->breachedAt,
            'met_at' => $timer->metAt,
            'cancelled_at' => $timer->cancelledAt,
            'strategy' => $outcome->strategy,
            'strategy_version' => $outcome->strategyVersion,
        ])->save();

        foreach ($outcome->events as $event) {
            $row->events()->create([
                'ticket_id' => $row->ticket_id,
                'type' => $event->type->value,
                'payload' => $event->toArray(),
                'created_at' => $event->at,
            ]);

            if (in_array($event->type, [SlaEventType::Warning, SlaEventType::Breached], true)) {
                TicketEvent::query()->create([
                    'ticket_id' => $row->ticket_id,
                    'type' => $event->type === SlaEventType::Warning ? 'sla_warning' : 'sla_breached',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'old_values' => [],
                    'new_values' => $event->toArray(),
                    'note' => null,
                    'created_at' => $event->at,
                ]);

                event($event->type === SlaEventType::Warning
                    ? new SlaWarning($row->tenant_id, $row->ticket_id, $row->id)
                    : new SlaBreached($row->tenant_id, $row->ticket_id, $row->id));
            }
        }
    }
}

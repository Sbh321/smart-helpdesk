<?php

declare(strict_types=1);

namespace App\Modules\Sla\Actions;

use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Exceptions\SlaTargetMissing;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Sla\Support\SlaCalendarResolver;
use App\Modules\Sla\Support\SlaStrategyFactory;
use App\Modules\Sla\Support\SlaTimerStore;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;

final readonly class AdvanceTicketSla
{
    public function __construct(
        private SlaStrategyFactory $strategies,
        private SlaCalendarResolver $calendars,
        private SlaTimerStore $store,
    ) {}

    /**
     * @throws SlaTargetMissing
     */
    public function __invoke(Ticket $ticket, TicketStatus $from, TicketStatus $to): void
    {
        if ($to !== TicketStatus::Pending
            && $from !== TicketStatus::Pending
            && $to !== TicketStatus::Resolved
            && ! ($to === TicketStatus::Closed && $ticket->duplicate_of_id !== null)
            && ! in_array($from, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return;
        }

        $rows = TicketSlaTimer::query()->with('policy')->where('ticket_id', $ticket->id)->lockForUpdate()->get();
        if (in_array($from, [TicketStatus::Resolved, TicketStatus::Closed], true)
            && $to === TicketStatus::InProgress) {
            $previous = $rows->where('kind', TimerKind::Resolution)->sortByDesc('cycle')->first();
            if ($previous === null) {
                return;
            }
            // MVP-SHORTCUT: the new cycle reuses the previous cycle's policy (at its current version and
            // calendar) instead of selecting again by Organisation tier; V1: re-selection when the
            // ticket's Organisation changes (docs/04-domain/sla.md §Policies).
            $policy = $previous->policy;
            $target = $policy->targetFor($ticket->effectivePriority());

            // At most one unfinished timer per ticket and kind (ticket_sla_timers_one_unfinished_key):
            // the old cycle is finished before the new row is inserted.
            $previousTimer = $this->store->read($previous);
            if (! $previousTimer->state->isFinal()) {
                $this->store->store($previous, $this->strategies
                    ->forWarningFraction((float) $previous->warning_fraction)->cancel($previousTimer));
            }

            // The store computes the deadlines with the calendar it stores: the policy's.
            $this->store->start(
                $ticket,
                $policy,
                TimerKind::Resolution,
                $target->resolution_minutes,
                $previous->cycle + 1,
                $this->strategies->forWarningFraction((float) $policy->warning_fraction),
            );

            return;
        }

        foreach ($rows as $row) {
            $timer = $this->store->read($row);
            $strategy = $this->strategies->forWarningFraction((float) $row->warning_fraction);
            if ($to === TicketStatus::Closed && $ticket->duplicate_of_id !== null
                && $timer->state->canTransitionTo(TimerState::Cancelled)) {
                $this->store->store($row, $strategy->cancel($timer));
            } elseif ($to === TicketStatus::Pending && $timer->state->canTransitionTo(TimerState::Paused)) {
                $this->store->store($row, $strategy->pause($timer), $timer->state);
            } elseif ($to === TicketStatus::Resolved && $row->kind === TimerKind::Resolution
                && $timer->state->canTransitionTo(TimerState::Met)) {
                $this->store->store($row, $strategy->complete($timer, $this->calendars->forId($row->calendar_id)));
            } elseif ($from === TicketStatus::Pending && $to !== TicketStatus::Resolved
                && $timer->state === TimerState::Paused) {
                $this->store->store($row, $strategy->resume($timer, $this->calendars->forId($row->calendar_id)));
            }
        }
    }
}

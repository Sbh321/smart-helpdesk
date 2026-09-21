<?php

declare(strict_types=1);

namespace App\Modules\Sla\Actions;

use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Sla\Support\SlaCalendarResolver;
use App\Modules\Sla\Support\SlaStrategyFactory;
use App\Modules\Sla\Support\SlaTimerStore;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** Recomputes unfinished deadlines after an effective priority change. */
final readonly class RecomputeTicketSla
{
    public function __construct(
        private SlaStrategyFactory $strategies,
        private SlaCalendarResolver $calendars,
        private SlaTimerStore $store,
    ) {}

    public function __invoke(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $rows = TicketSlaTimer::query()->with('policy.targets')
                ->where('ticket_id', $ticket->id)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $timer = $this->store->read($row);
                if ($timer->state->isFinal()) {
                    continue;
                }
                $target = $row->policy->targets->firstWhere('priority_level', $ticket->effectivePriority());
                if ($target === null) {
                    // Deliberately not SlaTargetMissing: this also runs inside the hourly priority
                    // re-evaluation, and the API always stores all four targets. The timer keeps its target.
                    continue;
                }
                $minutes = $row->kind === TimerKind::FirstResponse
                    ? $target->first_response_minutes : $target->resolution_minutes;
                if ($minutes === $row->target_minutes) {
                    continue;
                }
                $this->store->store($row, $this->strategies->forWarningFraction((float) $row->warning_fraction)
                    ->recompute($timer, $minutes * 60, $this->calendars->forId($row->calendar_id)), $row->paused_from_state);
            }
        });
    }
}

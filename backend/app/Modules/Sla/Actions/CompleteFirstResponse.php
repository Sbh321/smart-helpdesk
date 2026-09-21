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

/** Called by M2-07 after a public agent reply has been persisted. */
final readonly class CompleteFirstResponse
{
    public function __construct(
        private SlaStrategyFactory $strategies,
        private SlaCalendarResolver $calendars,
        private SlaTimerStore $store,
    ) {}

    public function __invoke(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $row = TicketSlaTimer::query()
                ->where('ticket_id', $ticket->id)
                ->where('kind', TimerKind::FirstResponse->value)
                ->orderByDesc('cycle')
                ->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $timer = $this->store->read($row);
            if ($timer->state->isFinal()) {
                return;
            }
            $this->store->store($row, $this->strategies->forWarningFraction((float) $row->warning_fraction)
                ->complete($timer, $this->calendars->forId($row->calendar_id)));
        });
    }
}

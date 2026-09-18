<?php

declare(strict_types=1);

namespace App\Modules\Sla\Contracts;

use App\Modules\Sla\Domain\Exceptions\InvalidTimerTransition;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use Carbon\CarbonImmutable;

/**
 * Runs an SLA timer through its life (docs/05-algorithms/sla-evaluation.md, ADR-0023).
 *
 * Every operation is pure: it takes the stored timer and returns the new timer plus the events to record.
 * "Now" comes from the Clock given to the implementation; the calendar is the SLA policy's calendar.
 * Operations not allowed in the timer's state throw {@see InvalidTimerTransition}.
 */
interface SlaStrategy
{
    /** Starts a timer now (ticket created, or reopened for a new resolution timer). */
    public function start(TimerKind $kind, int $targetSeconds, BusinessCalendar $calendar): TimerOutcome;

    /** The ticket became pending. */
    public function pause(TimerData $timer): TimerOutcome;

    /** The ticket left pending: deadlines move by the paused working time. */
    public function resume(TimerData $timer, BusinessCalendar $calendar): TimerOutcome;

    /** The goal was reached (first public agent reply, or ticket resolved). */
    public function complete(TimerData $timer, BusinessCalendar $calendar): TimerOutcome;

    /** The ticket was closed as a duplicate. */
    public function cancel(TimerData $timer): TimerOutcome;

    /** The priority changed: deadlines are recomputed from the start with the new target. */
    public function recompute(TimerData $timer, int $targetSeconds, BusinessCalendar $calendar): TimerOutcome;

    /** Emits the warning and breach events that are due at `$now` (each at most once). */
    public function check(TimerData $timer, ?CarbonImmutable $now = null): TimerOutcome;
}

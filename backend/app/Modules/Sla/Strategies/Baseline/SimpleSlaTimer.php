<?php

declare(strict_types=1);

namespace App\Modules\Sla\Strategies\Baseline;

use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Domain\Exceptions\InvalidSlaSettings;
use App\Modules\Sla\Domain\Exceptions\InvalidTimerTransition;
use App\Modules\Sla\Domain\Timer\SlaEvent;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Support\Attributes\AcademicBaseline;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;

/**
 * Simple SLA Timer: due = start + target on the policy calendar, warning at 75 %, paused while pending,
 * recomputed from the start when the priority changes (docs/05-algorithms/sla-evaluation.md).
 *
 * @deprecated Academic baseline for the CACS452 defence; replace after the defence (docs/adr/0023-minimal-replaceable-algorithms.md).
 */
#[AcademicBaseline]
final readonly class SimpleSlaTimer implements SlaStrategy
{
    public const string NAME = 'simple_sla_timer';

    public const string VERSION = '1.0.0';

    /**
     * @param  float  $warningFraction  config `helpdesk.sla.warning_fraction`
     */
    public function __construct(
        private Clock $clock,
        private float $warningFraction = 0.75,
    ) {
        if ($warningFraction <= 0 || $warningFraction >= 1) {
            throw new InvalidSlaSettings('The SLA warning fraction must be between 0 and 1.');
        }
    }

    public function start(TimerKind $kind, int $targetSeconds, BusinessCalendar $calendar): TimerOutcome
    {
        $this->assertTarget($targetSeconds);
        $now = $this->now();

        $timer = new TimerData(
            kind: $kind,
            state: TimerState::Running,
            targetSeconds: $targetSeconds,
            startedAt: $now,
            warningAt: $calendar->add($now, $this->warningSeconds($targetSeconds)),
            dueAt: $calendar->add($now, $targetSeconds),
        );

        return $this->outcome($timer, $this->event(SlaEventType::Started, $timer, $now, [
            'target_seconds' => $targetSeconds,
            'warning_at' => TimerData::format($timer->warningAt),
            'due_at' => TimerData::format($timer->dueAt),
        ]));
    }

    public function pause(TimerData $timer): TimerOutcome
    {
        $this->assertTransition($timer, TimerState::Paused);
        $now = $this->now();
        $paused = $timer->with(['state' => TimerState::Paused, 'pausedAt' => $now]);

        return $this->outcome($paused, $this->event(SlaEventType::Paused, $paused, $now));
    }

    public function resume(TimerData $timer, BusinessCalendar $calendar): TimerOutcome
    {
        if ($timer->state !== TimerState::Paused || $timer->pausedAt === null) {
            throw InvalidTimerTransition::operation('resumed', $timer->state);
        }

        $now = $this->now();
        $pausedSeconds = $calendar->elapsed($timer->pausedAt, $now);

        $resumed = $timer->with([
            // A timer that had already warned goes back to warning (diagram: paused → warning).
            'state' => $timer->warnedAt === null ? TimerState::Running : TimerState::Warning,
            'pausedAt' => null,
            'pausedTotalSeconds' => $timer->pausedTotalSeconds + $pausedSeconds,
            'warningAt' => $calendar->add($timer->warningAt, $pausedSeconds),
            'dueAt' => $calendar->add($timer->dueAt, $pausedSeconds),
        ]);

        return $this->outcome($resumed, $this->event(SlaEventType::Resumed, $resumed, $now, [
            'paused_seconds' => $pausedSeconds,
            'warning_at' => TimerData::format($resumed->warningAt),
            'due_at' => TimerData::format($resumed->dueAt),
        ]));
    }

    public function complete(TimerData $timer, BusinessCalendar $calendar): TimerOutcome
    {
        $this->assertTransition($timer, TimerState::Met);
        $now = $this->now();
        $pausedTotal = $timer->pausedTotalSeconds
            + ($timer->pausedAt === null ? 0 : $calendar->elapsed($timer->pausedAt, $now));

        $met = $timer->with([
            'state' => TimerState::Met,
            'metAt' => $now,
            'pausedAt' => null,
            'pausedTotalSeconds' => $pausedTotal,
        ]);

        return $this->outcome($met, $this->event(SlaEventType::Met, $met, $now, [
            'late' => $timer->breachedAt !== null,
        ]));
    }

    public function cancel(TimerData $timer): TimerOutcome
    {
        $this->assertTransition($timer, TimerState::Cancelled);
        $now = $this->now();
        $cancelled = $timer->with(['state' => TimerState::Cancelled, 'cancelledAt' => $now]);

        return $this->outcome($cancelled, $this->event(SlaEventType::Cancelled, $cancelled, $now));
    }

    public function recompute(TimerData $timer, int $targetSeconds, BusinessCalendar $calendar): TimerOutcome
    {
        if ($timer->state->isFinal()) {
            throw InvalidTimerTransition::operation('recomputed', $timer->state);
        }

        $this->assertTarget($targetSeconds);
        $now = $this->now();

        // Always from the start: due = cal.add(started_at, target + paused_total); a pause still open is added on resume.
        $recomputed = $timer->with([
            'targetSeconds' => $targetSeconds,
            'warningAt' => $calendar->add($timer->startedAt, $this->warningSeconds($targetSeconds) + $timer->pausedTotalSeconds),
            'dueAt' => $calendar->add($timer->startedAt, $targetSeconds + $timer->pausedTotalSeconds),
        ]);

        return $this->outcome($recomputed, $this->event(SlaEventType::Recomputed, $recomputed, $now, [
            'previous_target_seconds' => $timer->targetSeconds,
            'target_seconds' => $targetSeconds,
            'previous_due_at' => TimerData::format($timer->dueAt),
            'warning_at' => TimerData::format($recomputed->warningAt),
            'due_at' => TimerData::format($recomputed->dueAt),
        ]));
    }

    public function check(TimerData $timer, ?CarbonImmutable $now = null): TimerOutcome
    {
        $now ??= $this->now();
        $events = [];

        if (! $timer->state->isCounting()) {
            return $this->outcome($timer);
        }

        if ($timer->state === TimerState::Running && $timer->warnedAt === null
            && $now->greaterThanOrEqualTo($timer->warningAt) && $now->lessThan($timer->dueAt)) {
            $timer = $timer->with(['state' => TimerState::Warning, 'warnedAt' => $now]);
            $events[] = $this->event(SlaEventType::Warning, $timer, $now, ['due_at' => TimerData::format($timer->dueAt)]);
        }

        if ($timer->breachedAt === null && $now->greaterThanOrEqualTo($timer->dueAt)) {
            $timer = $timer->with(['state' => TimerState::Breached, 'breachedAt' => $now]);
            $events[] = $this->event(SlaEventType::Breached, $timer, $now, ['due_at' => TimerData::format($timer->dueAt)]);
        }

        return $this->outcome($timer, ...$events);
    }

    private function now(): CarbonImmutable
    {
        return $this->clock->now();
    }

    private function warningSeconds(int $targetSeconds): int
    {
        return (int) round($targetSeconds * $this->warningFraction);
    }

    private function assertTarget(int $targetSeconds): void
    {
        if ($targetSeconds <= 0) {
            throw new InvalidSlaSettings('An SLA target must be a positive number of seconds.');
        }
    }

    private function assertTransition(TimerData $timer, TimerState $next): void
    {
        if (! $timer->state->canTransitionTo($next)) {
            throw InvalidTimerTransition::between($timer->state, $next);
        }
    }

    /**
     * @param  array<string, scalar|null>  $details
     */
    private function event(SlaEventType $type, TimerData $timer, CarbonImmutable $at, array $details = []): SlaEvent
    {
        return new SlaEvent($type, $timer->kind, $at, $details);
    }

    private function outcome(TimerData $timer, SlaEvent ...$events): TimerOutcome
    {
        return new TimerOutcome($timer, array_values($events), self::NAME, self::VERSION);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Contracts;

use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidTimerTransition;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Closure;

/**
 * Expectations every SlaStrategy must meet (ADR-0023 §2), driven by a frozen clock. Call from a *Test.php file.
 */
final class SlaStrategyContract
{
    /**
     * @param  Closure(Clock): SlaStrategy  $make
     */
    public static function register(string $label, Closure $make): void
    {
        describe("{$label} (SlaStrategy contract)", function () use ($make): void {
            it('starts a running timer whose warning comes before the due time', function () use ($make): void {
                $clock = new FrozenClock('2026-09-17 09:00:00');
                $outcome = $make($clock)->start(TimerKind::Resolution, 3600, new TwentyFourSevenCalendar);
                $timer = $outcome->timer;

                expect($timer->state)->toBe(TimerState::Running)
                    ->and($timer->startedAt->equalTo($clock->now()))->toBeTrue()
                    ->and($timer->warningAt->greaterThan($timer->startedAt))->toBeTrue()
                    ->and($timer->warningAt->lessThan($timer->dueAt))->toBeTrue()
                    ->and($timer->dueAt->equalTo($clock->now()->addHour()))->toBeTrue()
                    ->and($outcome->has(SlaEventType::Started))->toBeTrue();
            });

            it('gives the same result for the same input and clock', function () use ($make): void {
                $calendar = new TwentyFourSevenCalendar;
                $a = $make(new FrozenClock('2026-09-17 09:00:00'))->start(TimerKind::FirstResponse, 600, $calendar);
                $b = $make(new FrozenClock('2026-09-17 09:00:00'))->start(TimerKind::FirstResponse, 600, $calendar);

                expect($a)->toEqual($b);
            });

            it('emits the warning once and the breach once however often it is checked', function () use ($make): void {
                $clock = new FrozenClock('2026-09-17 09:00:00');
                $strategy = $make($clock);
                $timer = $strategy->start(TimerKind::FirstResponse, 3600, new TwentyFourSevenCalendar)->timer;
                $events = [];

                for ($minute = 0; $minute <= 90; $minute++) {
                    $outcome = $strategy->check($timer, $clock->now()->addMinutes($minute));
                    $timer = $outcome->timer;
                    array_push($events, ...array_map(static fn ($e) => $e->type, $outcome->events));
                }

                expect($events)->toBe([SlaEventType::Warning, SlaEventType::Breached])
                    ->and($timer->state)->toBe(TimerState::Breached);
            });

            it('does not count paused time', function () use ($make): void {
                $clock = new FrozenClock('2026-09-17 09:00:00');
                $strategy = $make($clock);
                $calendar = new TwentyFourSevenCalendar;
                $timer = $strategy->start(TimerKind::Resolution, 3600, $calendar)->timer;
                $due = $timer->dueAt;

                $clock->advance('10 minutes');
                $timer = $strategy->pause($timer)->timer;
                expect($strategy->check($timer, $clock->now()->addDay())->events)->toBe([]);

                $clock->advance('30 minutes');
                $timer = $strategy->resume($timer, $calendar)->timer;

                expect($timer->dueAt->equalTo($due->addMinutes(30)))->toBeTrue()
                    ->and($timer->state)->toBe(TimerState::Running);
            });

            it('refuses to change a finished timer', function () use ($make): void {
                $clock = new FrozenClock('2026-09-17 09:00:00');
                $strategy = $make($clock);
                $calendar = new TwentyFourSevenCalendar;
                $met = $strategy->complete($strategy->start(TimerKind::Resolution, 3600, $calendar)->timer, $calendar)->timer;

                expect($met->state)->toBe(TimerState::Met)
                    ->and($strategy->check($met, $clock->now()->addYear())->events)->toBe([])
                    ->and(fn () => $strategy->pause($met))->toThrow(InvalidTimerTransition::class)
                    ->and(fn () => $strategy->cancel($met))->toThrow(InvalidTimerTransition::class)
                    ->and(fn () => $strategy->complete($met, $calendar))->toThrow(InvalidTimerTransition::class)
                    ->and(fn () => $strategy->recompute($met, 60, $calendar))->toThrow(InvalidTimerTransition::class);
            });

            it('explains itself with strategy, version, timer and events', function () use ($make): void {
                $outcome = $make(new FrozenClock('2026-09-17 09:00:00'))->start(TimerKind::Resolution, 3600, new TwentyFourSevenCalendar);
                $explanation = $outcome->explanation();

                expect($explanation['strategy'])->toBe($outcome->strategy)->not->toBeEmpty()
                    ->and($explanation['strategy_version'])->toBe($outcome->strategyVersion)->not->toBeEmpty()
                    ->and($explanation['timer']['state'])->toBe('running')
                    ->and($explanation['events'][0]['type'])->toBe('started')
                    ->and(json_encode($explanation, JSON_THROW_ON_ERROR))->toBeString();
            });
        });
    }
}

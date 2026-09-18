<?php

declare(strict_types=1);

use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidSlaSettings;
use App\Modules\Sla\Domain\Exceptions\InvalidTimerTransition;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use App\Modules\Sla\Domain\Timer\TimerState;
use App\Modules\Sla\Strategies\Baseline\SimpleSlaTimer;
use App\Support\Attributes\AcademicBaseline;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;

// Policy for P2 (docs/05-algorithms/sla-evaluation.md §Worked examples): first response 60 min, resolution 8 h; P1 resolution 4 h.
const P2_FIRST_RESPONSE = 3600;
const P2_RESOLUTION = 8 * 3600;
const P1_RESOLUTION = 4 * 3600;

/**
 * Drives one timer through a timeline on 24×7 (all times on 2026-09-17, UTC, unless a date is given).
 */
final class SlaTimeline
{
    public FrozenClock $clock;

    public SimpleSlaTimer $sla;

    public TimerData $timer;

    /** @var list<TimerOutcome> */
    public array $outcomes = [];

    public function __construct(
        TimerKind $kind,
        int $target,
        string $at = '09:00',
        public BusinessCalendar $calendar = new TwentyFourSevenCalendar,
        float $warningFraction = 0.75,
    ) {
        $this->clock = new FrozenClock(self::instant($at));
        $this->sla = new SimpleSlaTimer($this->clock, $warningFraction);
        $this->timer = $this->record($this->sla->start($kind, $target, $calendar));
    }

    public static function instant(string $at): CarbonImmutable
    {
        return str_contains($at, '-') ? CarbonImmutable::parse($at, 'UTC') : CarbonImmutable::parse("2026-09-17 {$at}", 'UTC');
    }

    public function at(string $at): self
    {
        $this->clock->set(self::instant($at));

        return $this;
    }

    public function pause(): self
    {
        $this->timer = $this->record($this->sla->pause($this->timer));

        return $this;
    }

    public function resume(): self
    {
        $this->timer = $this->record($this->sla->resume($this->timer, $this->calendar));

        return $this;
    }

    public function complete(): self
    {
        $this->timer = $this->record($this->sla->complete($this->timer, $this->calendar));

        return $this;
    }

    public function cancel(): self
    {
        $this->timer = $this->record($this->sla->cancel($this->timer));

        return $this;
    }

    public function recompute(int $target): self
    {
        $this->timer = $this->record($this->sla->recompute($this->timer, $target, $this->calendar));

        return $this;
    }

    /**
     * @return list<string> event types emitted by this check
     */
    public function check(string $at): array
    {
        $outcome = $this->sla->check($this->timer, self::instant($at));
        $this->timer = $this->record($outcome);

        return array_map(static fn ($event): string => $event->type->value, $outcome->events);
    }

    public function last(): TimerOutcome
    {
        return $this->outcomes[array_key_last($this->outcomes)];
    }

    public function hm(?CarbonImmutable $instant, string $zone = 'UTC'): ?string
    {
        return $instant?->setTimezone($zone)->format('H:i');
    }

    private function record(TimerOutcome $outcome): TimerData
    {
        $this->outcomes[] = $outcome;

        return $outcome->timer;
    }
}

it('timeline 1: first response met at 09:40; resolution warns at 15:00 and is due 17:00', function (): void {
    $firstResponse = new SlaTimeline(TimerKind::FirstResponse, P2_FIRST_RESPONSE);
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);

    $firstResponse->at('09:40')->complete();

    expect($firstResponse->timer->state)->toBe(TimerState::Met)
        ->and($firstResponse->hm($firstResponse->timer->metAt))->toBe('09:40')
        ->and($firstResponse->last()->events[0]->details)->toBe(['late' => false])
        ->and($firstResponse->check('11:00'))->toBe([])
        ->and($resolution->hm($resolution->timer->warningAt))->toBe('15:00')
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('17:00')
        ->and($resolution->last()->events[0]->details)->toBe([
            'target_seconds' => 28800, 'warning_at' => '2026-09-17T15:00:00Z', 'due_at' => '2026-09-17T17:00:00Z',
        ]);
});

it('timeline 2: pending 10:00–12:00 moves the warning to 17:00 and the due time to 19:00', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('10:00')->pause();

    expect($resolution->timer->state)->toBe(TimerState::Paused)
        ->and($resolution->check('18:00'))->toBe([]);

    $resolution->at('12:00')->resume();

    expect($resolution->timer->state)->toBe(TimerState::Running)
        ->and($resolution->hm($resolution->timer->warningAt))->toBe('17:00')
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('19:00')
        ->and($resolution->timer->pausedTotalSeconds)->toBe(7200)
        ->and($resolution->timer->pausedAt)->toBeNull()
        ->and($resolution->last()->events[0]->details)->toBe([
            'paused_seconds' => 7200, 'warning_at' => '2026-09-17T17:00:00Z', 'due_at' => '2026-09-17T19:00:00Z',
        ]);
});

it('timeline 3: no reply warns at 09:45 and breaches at 10:00, once each', function (): void {
    $firstResponse = new SlaTimeline(TimerKind::FirstResponse, P2_FIRST_RESPONSE);

    expect($firstResponse->check('09:44'))->toBe([])
        ->and($firstResponse->check('09:45'))->toBe(['warning'])
        ->and($firstResponse->timer->state)->toBe(TimerState::Warning)
        ->and($firstResponse->check('09:50'))->toBe([])
        ->and($firstResponse->check('10:00'))->toBe(['breached'])
        ->and($firstResponse->timer->state)->toBe(TimerState::Breached)
        ->and($firstResponse->check('10:30'))->toBe([])
        ->and($firstResponse->hm($firstResponse->timer->warnedAt))->toBe('09:45')
        ->and($firstResponse->hm($firstResponse->timer->breachedAt))->toBe('10:00');
});

it('timeline 4: priority raised to P1 at 13:00 makes it due 15:00 with a warning at 14:00', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('10:00')->pause()->at('12:00')->resume()->at('13:00')->recompute(P1_RESOLUTION);

    expect($resolution->hm($resolution->timer->dueAt))->toBe('15:00')
        ->and($resolution->hm($resolution->timer->warningAt))->toBe('14:00')
        ->and($resolution->timer->targetSeconds)->toBe(P1_RESOLUTION)
        ->and($resolution->timer->state)->toBe(TimerState::Running)
        ->and($resolution->last()->events[0]->details)->toBe([
            'previous_target_seconds' => 28800, 'target_seconds' => 14400, 'previous_due_at' => '2026-09-17T19:00:00Z',
            'warning_at' => '2026-09-17T14:00:00Z', 'due_at' => '2026-09-17T15:00:00Z',
        ])
        ->and($resolution->check('13:59'))->toBe([])
        ->and($resolution->check('14:00'))->toBe(['warning'])
        ->and($resolution->check('15:00'))->toBe(['breached']);
});

it('timeline 5: resolved at 16:00 and reopened at 16:30 starts a new resolution timer', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('16:00')->complete();
    $reopened = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION, '16:30');

    expect($resolution->timer->state)->toBe(TimerState::Met)
        ->and($reopened->timer->state)->toBe(TimerState::Running)
        ->and($reopened->hm($reopened->timer->startedAt))->toBe('16:30')
        ->and($reopened->timer->dueAt->toIso8601String())->toBe('2026-09-18T00:30:00+00:00')
        ->and($reopened->hm($reopened->timer->warningAt))->toBe('22:30');
});

it('runs the working-hours example: Thursday 15:00 is due Friday 16:00 with a warning at 14:00', function (): void {
    $office = new WorkingHoursCalendar('Asia/Kathmandu', array_fill_keys(['sun', 'mon', 'tue', 'wed', 'thu', 'fri'], [['10:00', '17:00']]));
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION, '2026-09-17 09:15', $office); // 15:00 in Kathmandu

    expect($timer->timer->dueAt->setTimezone('Asia/Kathmandu')->format('D H:i'))->toBe('Fri 16:00')
        ->and($timer->timer->warningAt->setTimezone('Asia/Kathmandu')->format('D H:i'))->toBe('Fri 14:00');

    // Pending from Thursday 16:00 to Sunday 11:00 uses 9 working hours, so the due time moves 9 working hours.
    $timer->at('2026-09-17 10:15')->pause()->at('2026-09-20 05:15')->resume();

    expect($timer->timer->pausedTotalSeconds)->toBe(9 * 3600)
        ->and($timer->timer->dueAt->setTimezone('Asia/Kathmandu')->format('D Y-m-d H:i'))->toBe('Mon 2026-09-21 11:00')
        ->and($timer->timer->warningAt->setTimezone('Asia/Kathmandu')->format('D Y-m-d H:i'))->toBe('Sun 2026-09-20 16:00');

    // A priority change recomputes from the start and includes the paused time:
    // 4 h + 9 h = 13 working hours from Thursday 15:00 (Thu 2 h, Fri 7 h, Sun 4 h); warning after 3 h + 9 h.
    $timer->recompute(P1_RESOLUTION);
    expect($timer->timer->dueAt->setTimezone('Asia/Kathmandu')->format('D H:i'))->toBe('Sun 14:00')
        ->and($timer->timer->warningAt->setTimezone('Asia/Kathmandu')->format('D H:i'))->toBe('Sun 13:00');
});

it('adds up several pending periods', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('10:00')->pause()->at('10:30')->resume()->at('11:00')->pause()->at('12:00')->resume();

    expect($resolution->timer->pausedTotalSeconds)->toBe(5400)
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('18:30')
        ->and($resolution->hm($resolution->timer->warningAt))->toBe('16:30');
});

it('returns to warning when a warned timer is resumed', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    expect($resolution->check('15:00'))->toBe(['warning']);

    $resolution->at('15:30')->pause()->at('16:00')->resume();

    expect($resolution->timer->state)->toBe(TimerState::Warning)
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('17:30')
        ->and($resolution->check('17:00'))->toBe([])
        ->and($resolution->check('17:30'))->toBe(['breached']);
});

it('keeps the warning state when a recompute moves the warning point later', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P1_RESOLUTION);
    expect($resolution->check('12:00'))->toBe(['warning']);

    $resolution->at('12:30')->recompute(P2_RESOLUTION);

    expect($resolution->timer->state)->toBe(TimerState::Warning)
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('17:00')
        ->and($resolution->check('15:00'))->toBe([])
        ->and($resolution->check('17:00'))->toBe(['breached']);
});

it('recomputes a paused timer and adds the open pause on resume', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('10:00')->pause()->at('11:00')->recompute(P1_RESOLUTION);

    expect($resolution->timer->state)->toBe(TimerState::Paused)
        ->and($resolution->hm($resolution->timer->dueAt))->toBe('13:00');

    $resolution->at('12:00')->resume();
    expect($resolution->hm($resolution->timer->dueAt))->toBe('15:00');
});

it('breaches straight away when a recompute puts the due time in the past', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('14:00')->recompute(P1_RESOLUTION);

    expect($resolution->check('14:01'))->toBe(['breached']);
});

it('only breaches when the first check comes after the due time', function (): void {
    $firstResponse = new SlaTimeline(TimerKind::FirstResponse, P2_FIRST_RESPONSE);

    expect($firstResponse->check('10:05'))->toBe(['breached'])
        ->and($firstResponse->timer->warnedAt)->toBeNull()
        ->and($firstResponse->check('10:06'))->toBe([]);
});

it('marks a goal reached after the breach as met late', function (): void {
    $firstResponse = new SlaTimeline(TimerKind::FirstResponse, P2_FIRST_RESPONSE);
    $firstResponse->check('10:00');
    $firstResponse->at('10:20')->complete();

    expect($firstResponse->timer->state)->toBe(TimerState::Met)
        ->and($firstResponse->last()->events[0]->details)->toBe(['late' => true])
        ->and($firstResponse->last()->has(SlaEventType::Met))->toBeTrue();
});

it('closes the open pause when a paused timer is met', function (): void {
    $resolution = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $resolution->at('10:00')->pause()->at('11:30')->complete();

    expect($resolution->timer->state)->toBe(TimerState::Met)
        ->and($resolution->timer->pausedAt)->toBeNull()
        ->and($resolution->timer->pausedTotalSeconds)->toBe(5400);
});

it('cancels an unfinished timer when the ticket is closed as a duplicate', function (string $state): void {
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);

    match ($state) {
        'paused' => $timer->at('10:00')->pause(),
        'warning' => $timer->check('15:00'),
        'breached' => $timer->check('17:00'),
        default => null,
    };

    $timer->at('17:05')->cancel();

    expect($timer->timer->state)->toBe(TimerState::Cancelled)
        ->and($timer->hm($timer->timer->cancelledAt))->toBe('17:05')
        ->and($timer->last()->has(SlaEventType::Cancelled))->toBeTrue()
        ->and($timer->check('23:00'))->toBe([])
        ->and(fn () => $timer->cancel())->toThrow(InvalidTimerTransition::class, 'from cancelled to cancelled');
})->with(['running', 'paused', 'warning', 'breached']);

it('rejects operations the state table does not allow', function (): void {
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);

    expect(fn () => $timer->resume())->toThrow(InvalidTimerTransition::class, 'in state running cannot be resumed');

    $timer->pause();
    expect(fn () => $timer->pause())->toThrow(InvalidTimerTransition::class, 'from paused to paused');

    $timer->resume()->check('17:00');
    expect(fn () => $timer->pause())->toThrow(InvalidTimerTransition::class, 'from breached to paused');

    $timer->complete();
    expect(fn () => $timer->recompute(60))->toThrow(InvalidTimerTransition::class, 'cannot be recomputed');
});

it('uses the clock when no instant is given to the check', function (): void {
    $timer = new SlaTimeline(TimerKind::FirstResponse, P2_FIRST_RESPONSE);
    $timer->clock->set(SlaTimeline::instant('09:45'));

    expect($timer->sla->check($timer->timer)->has(SlaEventType::Warning))->toBeTrue();
});

it('uses the configured warning fraction', function (): void {
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION, warningFraction: 0.5);

    expect($timer->hm($timer->timer->warningAt))->toBe('13:00')
        ->and(fn () => new SimpleSlaTimer(new FrozenClock, 0.0))->toThrow(InvalidSlaSettings::class)
        ->and(fn () => new SimpleSlaTimer(new FrozenClock, 1.0))->toThrow(InvalidSlaSettings::class);
});

it('rejects a target that is not positive', function (): void {
    $sla = new SimpleSlaTimer(new FrozenClock);
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);

    expect(fn () => $sla->start(TimerKind::Resolution, 0, new TwentyFourSevenCalendar))->toThrow(InvalidSlaSettings::class)
        ->and(fn () => $timer->recompute(-60))->toThrow(InvalidSlaSettings::class);
});

it('explains every outcome with strategy and version', function (): void {
    $timer = new SlaTimeline(TimerKind::Resolution, P2_RESOLUTION);
    $timer->at('10:00')->pause();

    expect($timer->last()->explanation())->toMatchArray(['strategy' => 'simple_sla_timer', 'strategy_version' => '1.0.0'])
        ->and($timer->last()->explanation()['events'])->toBe([
            ['type' => 'paused', 'kind' => 'resolution', 'at' => '2026-09-17T10:00:00Z', 'details' => []],
        ]);
});

it('is marked as an academic baseline', function (): void {
    expect((new ReflectionClass(SimpleSlaTimer::class))->getAttributes(AcademicBaseline::class))->toHaveCount(1)
        ->and((string) (new ReflectionClass(SimpleSlaTimer::class))->getDocComment())->toContain('@deprecated');
});

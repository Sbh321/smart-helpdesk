<?php

declare(strict_types=1);

use App\Modules\Sla\Domain\Timer\SlaEvent;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use App\Modules\Sla\Domain\Timer\TimerState;
use Carbon\CarbonImmutable;

function storedTimer(): TimerData
{
    return new TimerData(
        kind: TimerKind::Resolution,
        state: TimerState::Paused,
        targetSeconds: 28800,
        startedAt: CarbonImmutable::parse('2026-09-17 09:00', 'UTC'),
        warningAt: CarbonImmutable::parse('2026-09-17 15:00', 'UTC'),
        dueAt: CarbonImmutable::parse('2026-09-17 17:00', 'UTC'),
        pausedAt: CarbonImmutable::parse('2026-09-17 15:15:00+05:45'),
        pausedTotalSeconds: 60,
    );
}

it('serialises to an array in UTC and back', function (): void {
    $row = storedTimer()->toArray();

    expect($row)->toBe([
        'kind' => 'resolution',
        'state' => 'paused',
        'target_seconds' => 28800,
        'started_at' => '2026-09-17T09:00:00Z',
        'warning_at' => '2026-09-17T15:00:00Z',
        'due_at' => '2026-09-17T17:00:00Z',
        'paused_at' => '2026-09-17T09:30:00Z',
        'paused_total_seconds' => 60,
        'warned_at' => null,
        'breached_at' => null,
        'met_at' => null,
        'cancelled_at' => null,
    ])
        ->and(TimerData::fromArray($row)->toArray())->toBe($row)
        ->and(TimerData::fromArray([
            'kind' => 'first_response', 'state' => 'running', 'target_seconds' => 60,
            'started_at' => '2026-09-17T09:00:00Z', 'warning_at' => '2026-09-17T09:00:45Z', 'due_at' => '2026-09-17T09:01:00Z',
        ])->pausedTotalSeconds)->toBe(0);
});

it('copies with changes and refuses unknown fields', function (): void {
    $timer = storedTimer();
    $copy = $timer->with(['state' => TimerState::Running, 'pausedAt' => null]);

    expect($copy->state)->toBe(TimerState::Running)
        ->and($copy->pausedAt)->toBeNull()
        ->and($timer->state)->toBe(TimerState::Paused)
        ->and(fn () => $timer->with(['unknown' => 1]))->toThrow(InvalidArgumentException::class, 'no field unknown');
});

it('reports its events and explanation', function (): void {
    $at = CarbonImmutable::parse('2026-09-17 10:00', 'UTC');
    $outcome = new TimerOutcome(storedTimer(), [new SlaEvent(SlaEventType::Warning, TimerKind::Resolution, $at, ['due_at' => 'x'])], 'name', '9');

    expect($outcome->has(SlaEventType::Warning))->toBeTrue()
        ->and($outcome->has(SlaEventType::Breached))->toBeFalse()
        ->and($outcome->explanation()['events'])->toBe([[
            'type' => 'warning', 'kind' => 'resolution', 'at' => '2026-09-17T10:00:00Z', 'details' => ['due_at' => 'x'],
        ]])
        ->and(TimerData::format(null))->toBeNull();
});

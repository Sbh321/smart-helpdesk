<?php

declare(strict_types=1);

use App\Modules\Sla\Domain\Timer\TimerState;

it('allows exactly the transitions of the state table', function (): void {
    $allowed = [
        'running' => ['paused', 'warning', 'breached', 'met', 'cancelled'],
        'paused' => ['running', 'warning', 'met', 'cancelled'],
        'warning' => ['paused', 'breached', 'met', 'cancelled'],
        'breached' => ['met', 'cancelled'],
        'met' => [],
        'cancelled' => [],
    ];

    foreach (TimerState::cases() as $from) {
        foreach (TimerState::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} → {$to->value}");
        }
    }
});

it('knows which states are final and which are counted by the check', function (): void {
    expect(array_map(static fn (TimerState $s): string => $s->value, array_filter(TimerState::cases(), static fn (TimerState $s): bool => $s->isFinal())))
        ->toBe([4 => 'met', 5 => 'cancelled'])
        ->and(array_map(static fn (TimerState $s): string => $s->value, array_filter(TimerState::cases(), static fn (TimerState $s): bool => $s->isCounting())))
        ->toBe([0 => 'running', 2 => 'warning']);
});

<?php

declare(strict_types=1);

use App\Support\Time\FrozenClock;
use App\Support\Time\SystemClock;

it('stays at the instant it was given, in UTC', function (): void {
    $clock = new FrozenClock('2026-09-17 15:45:00+05:45');

    expect($clock->now()->toIso8601String())->toBe('2026-09-17T10:00:00+00:00')
        ->and($clock->now())->toEqual($clock->now());
});

it('moves only when advanced or set', function (): void {
    $clock = new FrozenClock('2026-09-01 09:00:00');

    $clock->advance('2 hours');
    expect($clock->now()->format('H:i'))->toBe('11:00');

    $clock->set('2026-09-02 08:30:00');
    expect($clock->now()->format('Y-m-d H:i'))->toBe('2026-09-02 08:30');
});

it('reports the system time in UTC', function (): void {
    expect((new SystemClock)->now()->getTimezone()->getName())->toBe('UTC');
});

<?php

declare(strict_types=1);

use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidCalendar;
use Carbon\CarbonImmutable;

it('adds and measures plain elapsed time', function (): void {
    $calendar = new TwentyFourSevenCalendar;
    $start = CarbonImmutable::parse('2026-09-17 09:00', 'UTC');

    expect($calendar->add($start, 8 * 3600)->toIso8601String())->toBe('2026-09-17T17:00:00+00:00')
        ->and($calendar->add($start, 0)->equalTo($start))->toBeTrue()
        ->and($calendar->elapsed($start, $start->addMinutes(90)))->toBe(5400)
        ->and($calendar->elapsed($start->addMinutes(90), $start))->toBe(0);
});

it('counts real seconds across a daylight-saving change and keeps the input time zone', function (): void {
    $calendar = new TwentyFourSevenCalendar;
    $start = CarbonImmutable::parse('2026-03-08 01:30', 'America/New_York');
    $due = $calendar->add($start, 3600);

    expect($due->toIso8601String())->toBe('2026-03-08T03:30:00-04:00')
        ->and($calendar->elapsed($start, $due))->toBe(3600);
});

it('rejects a negative duration', function (): void {
    expect(fn () => (new TwentyFourSevenCalendar)->add(CarbonImmutable::now(), -1))->toThrow(InvalidCalendar::class);
});

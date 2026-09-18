<?php

declare(strict_types=1);

use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Domain\Exceptions\InvalidCalendar;
use Carbon\CarbonImmutable;

/**
 * Office hours Sunday–Friday 10:00–17:00 in Asia/Kathmandu (docs/05-algorithms/sla-evaluation.md §Worked examples).
 *
 * @param  list<string>  $holidays
 */
function kathmanduOffice(array $holidays = []): WorkingHoursCalendar
{
    $day = [['10:00', '17:00']];

    return new WorkingHoursCalendar('Asia/Kathmandu', [
        'sun' => $day, 'mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day,
    ], $holidays);
}

function ktm(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Kathmandu');
}

function wallTime(CarbonImmutable $instant, string $zone = 'Asia/Kathmandu'): string
{
    return $instant->setTimezone($zone)->format('D Y-m-d H:i');
}

it('reproduces the working-hours example: Thursday 15:00 + 8 h is due Friday 16:00, warning Friday 14:00', function (): void {
    $calendar = kathmanduOffice();
    $created = ktm('2026-09-17 15:00');

    expect(wallTime($calendar->add($created, 8 * 3600)))->toBe('Fri 2026-09-18 16:00')
        ->and(wallTime($calendar->add($created, 6 * 3600)))->toBe('Fri 2026-09-18 14:00')
        ->and($calendar->elapsed($created, ktm('2026-09-18 16:00')))->toBe(8 * 3600);
});

it('skips the closed Saturday: created Friday 16:00 is due the next Sunday', function (): void {
    $calendar = kathmanduOffice();

    expect(wallTime($calendar->add(ktm('2026-09-18 16:00'), 8 * 3600)))->toBe('Sun 2026-09-20 17:00')
        ->and($calendar->elapsed(ktm('2026-09-19 08:00'), ktm('2026-09-19 20:00')))->toBe(0);
});

it('keeps the time zone of the input instant', function (): void {
    $due = kathmanduOffice()->add(CarbonImmutable::parse('2026-09-17 09:15', 'UTC'), 8 * 3600);

    expect($due->toIso8601String())->toBe('2026-09-18T10:15:00+00:00');
});

it('skips a holiday inside the timer', function (): void {
    $calendar = kathmanduOffice(['2026-09-18']);
    $created = ktm('2026-09-17 15:00');

    expect(wallTime($calendar->add($created, 8 * 3600)))->toBe('Sun 2026-09-20 16:00')
        ->and($calendar->elapsed($created, ktm('2026-09-20 16:00')))->toBe(8 * 3600)
        ->and($calendar->isHoliday(CarbonImmutable::parse('2026-09-18 03:00', 'UTC')))->toBeTrue()
        ->and($calendar->isHoliday(CarbonImmutable::parse('2026-09-18 20:00', 'UTC')))->toBeFalse();
});

it('starts counting at the next opening when a timer starts outside working hours', function (string $start, int $seconds, string $expected): void {
    expect(wallTime(kathmanduOffice()->add(ktm($start), $seconds)))->toBe($expected);
})->with([
    'on the closed Saturday' => ['2026-09-19 12:00', 3600, 'Sun 2026-09-20 11:00'],
    'before opening' => ['2026-09-17 08:00', 3600, 'Thu 2026-09-17 11:00'],
    'after closing' => ['2026-09-17 18:00', 3600, 'Fri 2026-09-18 11:00'],
    'exactly at closing' => ['2026-09-17 17:00', 60, 'Fri 2026-09-18 10:01'],
    'zero duration stays put' => ['2026-09-19 12:00', 0, 'Sat 2026-09-19 12:00'],
    'a whole working week' => ['2026-09-17 15:00', 6 * 7 * 3600, 'Thu 2026-09-24 15:00'],
]);

it('measures working time between two instants', function (string $from, string $to, int $expected): void {
    expect(kathmanduOffice()->elapsed(ktm($from), ktm($to)))->toBe($expected);
})->with([
    'within one day' => ['2026-09-17 11:00', '2026-09-17 12:30', 5400],
    'both outside hours on one day' => ['2026-09-17 06:00', '2026-09-17 20:00', 7 * 3600],
    'Thursday evening to Sunday morning' => ['2026-09-17 16:00', '2026-09-20 11:00', 9 * 3600],
    'reversed' => ['2026-09-18 16:00', '2026-09-17 15:00', 0],
    'same instant' => ['2026-09-17 11:00', '2026-09-17 11:00', 0],
]);

it('supports several windows per day given in any order', function (): void {
    $calendar = new WorkingHoursCalendar('Asia/Kathmandu', ['mon' => [['13:00', '17:00'], ['09:00', '12:00']]]);

    expect(wallTime($calendar->add(ktm('2026-09-21 11:00'), 2 * 3600)))->toBe('Mon 2026-09-21 14:00')
        ->and($calendar->elapsed(ktm('2026-09-21 08:00'), ktm('2026-09-21 18:00')))->toBe(7 * 3600)
        ->and(wallTime($calendar->add(ktm('2026-09-21 16:00'), 2 * 3600)))->toBe('Mon 2026-09-28 10:00');
});

it('handles the spring-forward change in America/New_York', function (): void {
    $allDay = array_fill_keys(WorkingHoursCalendar::DAYS, [['00:00', '24:00']]);
    $calendar = new WorkingHoursCalendar('America/New_York', $allDay);
    $saturdayNight = CarbonImmutable::parse('2026-03-07 22:00', 'America/New_York');

    expect($calendar->add($saturdayNight, 6 * 3600)->toIso8601String())->toBe('2026-03-08T05:00:00-04:00')
        ->and($calendar->elapsed(
            CarbonImmutable::parse('2026-03-08 00:00', 'America/New_York'),
            CarbonImmutable::parse('2026-03-09 00:00', 'America/New_York'),
        ))->toBe(23 * 3600);

    // A window that spans the missing hour is one hour shorter.
    $early = new WorkingHoursCalendar('America/New_York', ['sun' => [['01:00', '04:00']]]);
    expect($early->elapsed(
        CarbonImmutable::parse('2026-03-08 00:00', 'America/New_York'),
        CarbonImmutable::parse('2026-03-08 12:00', 'America/New_York'),
    ))->toBe(2 * 3600);
});

it('carries a timer over a weekend with a daylight-saving change', function (): void {
    $weekdays = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], [['09:00', '17:00']]);
    $calendar = new WorkingHoursCalendar('America/New_York', $weekdays);
    $friday = CarbonImmutable::parse('2026-03-06 16:00', 'America/New_York');
    $due = $calendar->add($friday, 2 * 3600);

    expect($due->toIso8601String())->toBe('2026-03-09T10:00:00-04:00')
        ->and($due->utc()->format('H:i'))->toBe('14:00')
        ->and($calendar->elapsed($friday, $due))->toBe(2 * 3600);
});

it('handles the autumn change in Europe/London', function (): void {
    $calendar = new WorkingHoursCalendar('Europe/London', ['sun' => [['00:00', '24:00']], 'mon' => [['00:00', '03:00']]]);
    $midnight = CarbonImmutable::parse('2026-10-25 00:00', 'Europe/London');

    expect($calendar->elapsed($midnight, CarbonImmutable::parse('2026-10-26 00:00', 'Europe/London')))->toBe(25 * 3600)
        ->and($calendar->add($midnight, 25 * 3600)->toIso8601String())->toBe('2026-10-26T00:00:00+00:00')
        ->and($calendar->add($midnight, 26 * 3600)->toIso8601String())->toBe('2026-10-26T01:00:00+00:00');

    $early = new WorkingHoursCalendar('Europe/London', ['sun' => [['00:00', '03:00']]]);
    expect($early->elapsed($midnight, $midnight->addDay()))->toBe(4 * 3600);
});

it('rejects invalid calendars', function (string $timezone, array $hours, array $holidays, string $message): void {
    expect(fn () => new WorkingHoursCalendar($timezone, $hours, $holidays))->toThrow(InvalidCalendar::class, $message);
})->with([
    'unknown time zone' => ['Mars/Olympus', ['mon' => [['09:00', '17:00']]], [], 'Unknown time zone'],
    'unknown weekday' => ['UTC', ['monday' => [['09:00', '17:00']]], [], 'Unknown weekday'],
    'malformed time' => ['UTC', ['mon' => [['9:00', '17:00']]], [], 'Invalid time'],
    '24:00 as a start' => ['UTC', ['mon' => [['24:00', '24:00']]], [], 'Invalid time'],
    'end before start' => ['UTC', ['mon' => [['17:00', '09:00']]], [], 'must end after it starts'],
    'overlapping windows' => ['UTC', ['mon' => [['09:00', '13:00'], ['12:00', '17:00']]], [], 'overlap'],
    'no windows' => ['UTC', [], [], 'at least one working window'],
    'only empty days' => ['UTC', ['mon' => []], [], 'at least one working window'],
    'impossible holiday' => ['UTC', ['mon' => [['09:00', '17:00']]], ['2026-02-30'], 'Invalid holiday'],
    'badly formatted holiday' => ['UTC', ['mon' => [['09:00', '17:00']]], ['17/09/2026'], 'Invalid holiday'],
]);

it('rejects a negative duration', function (): void {
    expect(fn () => kathmanduOffice()->add(ktm('2026-09-17 10:00'), -1))->toThrow(InvalidCalendar::class);
});

it('stops instead of looping forever when no working time is left', function (): void {
    $mondays = [];

    for ($day = ktm('2026-09-21'); $day->year < 2037; $day = $day->addWeek()) {
        $mondays[] = $day->format('Y-m-d');
    }

    $calendar = new WorkingHoursCalendar('Asia/Kathmandu', ['mon' => [['09:00', '17:00']]], $mondays);

    expect(fn () => $calendar->add(ktm('2026-09-17 10:00'), 60))->toThrow(InvalidCalendar::class, 'no working time left');
});

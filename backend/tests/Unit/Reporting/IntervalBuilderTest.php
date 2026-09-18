<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Exceptions\InvalidHistory;
use App\Modules\Reporting\Domain\Intervals\IntervalBuilder;
use App\Modules\Reporting\Domain\Intervals\IntervalMeasures;
use App\Modules\Reporting\Domain\Intervals\StateEvent;
use App\Modules\Reporting\Domain\Intervals\StateInterval;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;

/** Office hours Sunday–Friday 10:00–17:00 in Asia/Kathmandu, as in the SLA worked examples. */
function rptOffice(): WorkingHoursCalendar
{
    $day = [['10:00', '17:00']];

    return new WorkingHoursCalendar('Asia/Kathmandu', ['sun' => $day, 'mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day]);
}

function rptKtm(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Kathmandu');
}

/**
 * @param  array<string, mixed>  $sets
 */
function rptEvent(string $id, string $local, array $sets): StateEvent
{
    return new StateEvent($id, rptKtm($local), $sets);
}

const RPT_CREATED = ['status' => 'open', 'assignee_id' => null, 'team_id' => 'support', 'priority' => 'P3'];

/**
 * The worked example in history-and-time-analytics.md §4 (Thursday 17 September 2026, Kathmandu office hours).
 *
 * @return list<StateEvent>
 */
function rptLifecycle(): array
{
    return [
        rptEvent('e1', '2026-09-17 15:30', ['status' => 'assigned', 'assignee_id' => 'asha']),
        rptEvent('e2', '2026-09-17 16:00', ['status' => 'in_progress']),
        rptEvent('e3', '2026-09-18 16:30', ['status' => 'pending']),
        rptEvent('e4', '2026-09-20 11:00', ['status' => 'in_progress']),
        rptEvent('e5', '2026-09-20 12:00', ['assignee_id' => 'chen']),
        rptEvent('e6', '2026-09-20 14:00', ['status' => 'resolved']),
    ];
}

/**
 * @param  list<StateInterval>  $intervals
 * @return list<array{string, ?string, string, ?string, int, int}>
 */
function rptRows(array $intervals): array
{
    return array_map(fn (StateInterval $i): array => [
        $i->get('status'),
        $i->get('assignee_id'),
        $i->startsAt->setTimezone('Asia/Kathmandu')->format('D H:i'),
        $i->endsAt?->setTimezone('Asia/Kathmandu')->format('D H:i'),
        $i->seconds,
        $i->businessSeconds,
    ], $intervals);
}

function rptBuilder(string $now = '2026-09-21 04:15:00'): IntervalBuilder
{
    return new IntervalBuilder(new FrozenClock($now));   // 04:15 UTC = Monday 10:00 in Kathmandu
}

it('reproduces the lifecycle worked example with business durations across the closed Saturday', function (): void {
    $intervals = rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), rptLifecycle(), rptOffice());

    expect(rptRows($intervals))->toBe([
        ['open', null, 'Thu 15:00', 'Thu 15:30', 1800, 1800],
        ['assigned', 'asha', 'Thu 15:30', 'Thu 16:00', 1800, 1800],
        ['in_progress', 'asha', 'Thu 16:00', 'Fri 16:30', 88200, 27000],
        ['pending', 'asha', 'Fri 16:30', 'Sun 11:00', 153000, 5400],
        ['in_progress', 'asha', 'Sun 11:00', 'Sun 12:00', 3600, 3600],
        ['in_progress', 'chen', 'Sun 12:00', 'Sun 14:00', 7200, 7200],
        ['resolved', 'chen', 'Sun 14:00', null, 72000, 10800],
    ])->and(array_column(array_map(fn (StateInterval $i): array => ['s' => $i->sequence], $intervals), 's'))->toBe([0, 1, 2, 3, 4, 5, 6]);
});

it('derives time in status, reassignments and the state at an instant from the worked example', function (): void {
    $intervals = rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), rptLifecycle(), rptOffice());

    expect(IntervalMeasures::timeBy($intervals, 'status'))->toBe([
        'open' => ['seconds' => 1800, 'business_seconds' => 1800],
        'assigned' => ['seconds' => 1800, 'business_seconds' => 1800],
        'in_progress' => ['seconds' => 99000, 'business_seconds' => 37800],
        'pending' => ['seconds' => 153000, 'business_seconds' => 5400],
        'resolved' => ['seconds' => 72000, 'business_seconds' => 10800],
    ])->and(IntervalMeasures::timeBy($intervals, 'assignee_id')[''])->toBe(['seconds' => 1800, 'business_seconds' => 1800])
        ->and(IntervalMeasures::switches($intervals, 'assignee_id'))->toBe(1)
        ->and(IntervalMeasures::switches($intervals, 'team_id'))->toBe(0)
        ->and(IntervalMeasures::at($intervals, rptKtm('2026-09-19 12:00'))?->get('status'))->toBe('pending')
        ->and(IntervalMeasures::at($intervals, rptKtm('2026-09-20 12:00'))?->get('assignee_id'))->toBe('chen')
        ->and(IntervalMeasures::at($intervals, rptKtm('2026-12-01'))?->get('status'))->toBe('resolved')
        ->and(IntervalMeasures::at($intervals, rptKtm('2026-09-17 14:59')))->toBeNull();
});

it('leaves a ticket without events in one open interval measured to the clock', function (): void {
    $intervals = rptBuilder('2026-09-17 09:45:00')->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), [], new TwentyFourSevenCalendar);

    expect($intervals)->toHaveCount(1)
        ->and($intervals[0]->isOpen())->toBeTrue()
        ->and($intervals[0]->state)->toBe(RPT_CREATED)
        ->and($intervals[0]->seconds)->toBe(1800)
        ->and($intervals[0]->businessSeconds)->toBe(1800);
});

it('measures the open interval to an explicit now instead of the clock', function (): void {
    $intervals = rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), [], rptOffice(), rptKtm('2026-09-18 11:00'));

    expect($intervals[0]->endsAt)->toBeNull()
        ->and($intervals[0]->seconds)->toBe(20 * 3600)
        ->and($intervals[0]->businessSeconds)->toBe(3 * 3600);
});

it('gives zero durations when now is before the open interval starts', function (): void {
    $intervals = rptBuilder('2026-09-01 00:00:00')->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), [], rptOffice());

    expect([$intervals[0]->seconds, $intervals[0]->businessSeconds])->toBe([0, 0]);
});

it('keeps a zero-length interval when two changes happen at the same instant, ordered by event id', function (): void {
    $events = [
        rptEvent('e2', '2026-09-17 16:00', ['status' => 'in_progress']),
        rptEvent('e1', '2026-09-17 16:00', ['status' => 'assigned', 'assignee_id' => 'asha']),
    ];

    expect(rptRows(rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), $events, rptOffice(), rptKtm('2026-09-17 17:00'))))->toBe([
        ['open', null, 'Thu 15:00', 'Thu 16:00', 3600, 3600],
        ['assigned', 'asha', 'Thu 16:00', 'Thu 16:00', 0, 0],
        ['in_progress', 'asha', 'Thu 16:00', null, 3600, 3600],
    ]);
});

it('does not split an interval for events that change nothing tracked', function (): void {
    $events = [
        rptEvent('e1', '2026-09-17 15:10', ['status' => 'open']),
        rptEvent('e2', '2026-09-17 15:20', ['subject' => 'Printer offline', 'category_id' => 'hardware']),
        rptEvent('e3', '2026-09-17 15:30', ['priority' => 'P2']),
    ];

    expect(rptRows(rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), $events, new TwentyFourSevenCalendar, rptKtm('2026-09-17 16:00'))))->toBe([
        ['open', null, 'Thu 15:00', 'Thu 15:30', 1800, 1800],
        ['open', null, 'Thu 15:30', null, 1800, 1800],
    ]);
});

it('covers reopen, unassign, team change and duplicate close', function (): void {
    $events = [
        rptEvent('e1', '2026-09-17 15:10', ['status' => 'assigned', 'assignee_id' => 'asha']),
        rptEvent('e2', '2026-09-17 15:20', ['status' => 'resolved']),
        rptEvent('e3', '2026-09-17 15:30', ['status' => 'open']),                              // reopened
        rptEvent('e4', '2026-09-17 15:40', ['assignee_id' => null, 'team_id' => 'billing']),   // unassigned and moved
        rptEvent('e5', '2026-09-17 15:50', ['status' => 'closed']),                            // closed as duplicate
    ];

    $intervals = rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), $events, new TwentyFourSevenCalendar, rptKtm('2026-09-17 16:00'));

    expect(array_map(fn (StateInterval $i): array => array_values($i->state), $intervals))->toBe([
        ['open', null, 'support', 'P3'],
        ['assigned', 'asha', 'support', 'P3'],
        ['resolved', 'asha', 'support', 'P3'],
        ['open', 'asha', 'support', 'P3'],
        ['open', null, 'billing', 'P3'],
        ['closed', null, 'billing', 'P3'],
    ])->and(IntervalMeasures::switches($intervals, 'assignee_id'))->toBe(0)
        ->and(IntervalMeasures::switches($intervals, 'team_id'))->toBe(1)
        ->and(IntervalMeasures::timeBy($intervals, 'status')['open']['seconds'])->toBe(1800)
        ->and(end($intervals)->isOpen())->toBeTrue();
});

it('counts wall-clock time with the 24x7 calendar across a weekend and a daylight-saving change', function (): void {
    $start = CarbonImmutable::parse('2026-10-24 12:00', 'Europe/London');   // clocks go back on Sunday 25 October
    $events = [new StateEvent('e1', CarbonImmutable::parse('2026-10-26 12:00', 'Europe/London'), ['status' => 'pending'])];

    $intervals = rptBuilder()->build(['status' => 'open'], $start, $events, new TwentyFourSevenCalendar, CarbonImmutable::parse('2026-10-26 13:00', 'Europe/London'));

    expect([$intervals[0]->seconds, $intervals[0]->businessSeconds])->toBe([49 * 3600, 49 * 3600])
        ->and([$intervals[1]->seconds, $intervals[1]->businessSeconds])->toBe([3600, 3600]);
});

it('tracks any attribute set, not only ticket attributes', function (): void {
    $events = [new StateEvent('e1', rptKtm('2026-09-17 11:00'), ['available' => false, 'status' => 'ignored'])];

    $intervals = rptBuilder()->build(['available' => true], rptKtm('2026-09-17 10:00'), $events, rptOffice(), rptKtm('2026-09-17 12:00'));

    expect(array_map(fn (StateInterval $i): array => $i->state, $intervals))->toBe([['available' => true], ['available' => false]])
        ->and(IntervalBuilder::TICKET_ATTRIBUTES)->toBe(['status', 'assignee_id', 'team_id', 'priority']);
});

it('rejects an empty initial state and events before the start', function (): void {
    expect(fn () => rptBuilder()->build([], rptKtm('2026-09-17 15:00'), [], rptOffice()))
        ->toThrow(InvalidHistory::class, 'at least one tracked attribute')
        ->and(fn () => rptBuilder()->build(RPT_CREATED, rptKtm('2026-09-17 15:00'), [rptEvent('e0', '2026-09-17 14:00', ['status' => 'assigned'])], rptOffice()))
        ->toThrow(InvalidHistory::class, 'before the entity was created');
});

it('covers instants with a half-open range', function (): void {
    $closed = new StateInterval(0, [], rptKtm('2026-09-17 10:00'), rptKtm('2026-09-17 11:00'), 3600, 3600);

    expect($closed->covers(rptKtm('2026-09-17 10:00')))->toBeTrue()
        ->and($closed->covers(rptKtm('2026-09-17 11:00')))->toBeFalse()
        ->and($closed->isOpen())->toBeFalse()
        ->and($closed->get('status'))->toBeNull();
});

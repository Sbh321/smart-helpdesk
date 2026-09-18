<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Exceptions\InvalidHistory;
use App\Modules\Reporting\Domain\History\ChangeOperation;
use App\Modules\Reporting\Domain\History\ChangeReplayer;
use App\Modules\Reporting\Domain\History\EntityChange;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

function rptUtc(string $instant): CarbonImmutable
{
    return CarbonImmutable::parse($instant, 'UTC');
}

/**
 * @param  array<string, mixed>  $row
 */
function rptInsert(int $version, string $at, array $row): EntityChange
{
    return new EntityChange($version, ChangeOperation::Insert, rptUtc($at), array_map(fn (mixed $v): array => ['old' => null, 'new' => $v], $row));
}

/**
 * @param  array<string, array{mixed, mixed}>  $diff  attribute => [old, new]
 */
function rptUpdate(int $version, string $at, array $diff): EntityChange
{
    return new EntityChange($version, ChangeOperation::Update, rptUtc($at), array_map(fn (array $p): array => ['old' => $p[0], 'new' => $p[1]], $diff));
}

/**
 * The worked example in history-and-time-analytics.md §3: a ticket created, assigned, raised to P2 and resolved.
 *
 * @return list<EntityChange>
 */
function rptTicketHistory(): array
{
    return [
        rptInsert(1, '2026-09-01 09:00', ['status' => 'open', 'priority' => 'P3', 'assignee_id' => null, 'subject' => 'Printer offline']),
        rptUpdate(2, '2026-09-01 10:00', ['status' => ['open', 'assigned'], 'assignee_id' => [null, 'asha']]),
        rptUpdate(3, '2026-09-02 09:00', ['priority' => ['P3', 'P2']]),
        rptUpdate(4, '2026-09-03 12:00', ['status' => ['assigned', 'resolved']]),
    ];
}

const RPT_TICKET_NOW = ['status' => 'resolved', 'priority' => 'P2', 'assignee_id' => 'asha', 'subject' => 'Printer offline'];

it('reproduces the as-of worked example by backward replay', function (): void {
    $replayer = new ChangeReplayer;
    $changes = rptTicketHistory();

    expect($replayer->asOf(RPT_TICKET_NOW, $changes, rptUtc('2026-09-02 08:00')))
        ->toBe(['status' => 'assigned', 'priority' => 'P3', 'assignee_id' => 'asha', 'subject' => 'Printer offline'])
        ->and($replayer->asOf(RPT_TICKET_NOW, $changes, rptUtc('2026-09-01 09:30')))
        ->toBe(['status' => 'open', 'priority' => 'P3', 'assignee_id' => null, 'subject' => 'Printer offline'])
        ->and($replayer->asOf(RPT_TICKET_NOW, $changes, rptUtc('2026-09-04 00:00')))->toBe(RPT_TICKET_NOW)
        ->and($replayer->asOf(RPT_TICKET_NOW, $changes, rptUtc('2026-08-31 23:59')))->toBeNull();
});

it('reproduces the worked example by forward replay', function (): void {
    $replayer = new ChangeReplayer;

    expect($replayer->forwardTo(rptTicketHistory(), rptUtc('2026-09-02 08:00')))
        ->toBe(['status' => 'assigned', 'priority' => 'P3', 'assignee_id' => 'asha', 'subject' => 'Printer offline'])
        ->and($replayer->forwardTo(rptTicketHistory(), rptUtc('2026-09-05')))->toBe(RPT_TICKET_NOW)
        ->and($replayer->forwardTo(rptTicketHistory(), rptUtc('2026-08-31')))->toBeNull();
});

it('counts a change made exactly at the instant as already applied', function (): void {
    $replayer = new ChangeReplayer;
    $at = rptUtc('2026-09-01 10:00');

    expect($replayer->asOf(RPT_TICKET_NOW, rptTicketHistory(), $at)['status'])->toBe('assigned')
        ->and($replayer->forwardTo(rptTicketHistory(), $at)['status'])->toBe('assigned')
        ->and($replayer->asOf(RPT_TICKET_NOW, rptTicketHistory(), rptUtc('2026-09-01 09:00')))->not->toBeNull();
});

it('reconstructs a deleted entity from the old values of its delete change', function (): void {
    $replayer = new ChangeReplayer;
    $changes = [...rptTicketHistory(), new EntityChange(5, ChangeOperation::Delete, rptUtc('2026-09-04 08:00'), array_map(
        fn (mixed $v): array => ['old' => $v, 'new' => null],
        RPT_TICKET_NOW,
    ))];

    expect($replayer->asOf(null, $changes, rptUtc('2026-09-03 13:00')))->toBe(RPT_TICKET_NOW)
        ->and($replayer->asOf(null, $changes, rptUtc('2026-09-02 08:00'))['priority'])->toBe('P3')
        ->and($replayer->asOf(null, $changes, rptUtc('2026-09-05')))->toBeNull()
        ->and($replayer->forwardTo($changes, rptUtc('2026-09-05')))->toBeNull()
        ->and($replayer->forwardTo($changes, rptUtc('2026-09-03 13:00')))->toBe(RPT_TICKET_NOW);
});

it('only needs the changes after the instant and accepts them in any order', function (): void {
    $replayer = new ChangeReplayer;
    $newer = array_reverse(array_slice(rptTicketHistory(), 2));

    expect($replayer->asOf(RPT_TICKET_NOW, $newer, rptUtc('2026-09-02 08:00'))['priority'])->toBe('P3')
        ->and($replayer->forwardTo(array_reverse(rptTicketHistory()), rptUtc('2026-09-02 10:00'))['priority'])->toBe('P2');
});

it('keeps unrecorded columns at their current value in backward replay', function (): void {
    $current = [...RPT_TICKET_NOW, 'updated_at' => '2026-09-03 12:00:00'];

    expect((new ChangeReplayer)->asOf($current, rptTicketHistory(), rptUtc('2026-09-01 09:30')))
        ->toMatchArray(['status' => 'open', 'updated_at' => '2026-09-03 12:00:00']);
});

it('forward-replays from a given initial state when history starts after creation', function (): void {
    $changes = [rptUpdate(7, '2026-09-10 10:00', ['status' => ['open', 'pending']])];

    expect((new ChangeReplayer)->forwardTo($changes, rptUtc('2026-09-11'), ['status' => 'open', 'priority' => 'P4']))
        ->toBe(['status' => 'pending', 'priority' => 'P4']);
});

it('rejects inconsistent histories', function (array $changes, ?array $initial, string $message): void {
    expect(fn () => (new ChangeReplayer)->forwardTo($changes, rptUtc('2026-12-31'), $initial))
        ->toThrow(InvalidHistory::class, $message);
})->with([
    'duplicate version' => [[rptInsert(1, '2026-09-01', ['a' => 1]), rptUpdate(1, '2026-09-02', ['a' => [1, 2]])], null, 'appears twice'],
    'time going backwards' => [[rptInsert(1, '2026-09-02', ['a' => 1]), rptUpdate(2, '2026-09-01', ['a' => [1, 2]])], null, 'older than version 1'],
    'update before insert' => [[rptUpdate(1, '2026-09-01', ['a' => [1, 2]])], null, 'does not exist'],
    'second insert' => [[rptInsert(1, '2026-09-01', ['a' => 1])], ['a' => 0], 'already exists'],
    'delete of nothing' => [[new EntityChange(1, ChangeOperation::Delete, rptUtc('2026-09-01'), ['a' => ['old' => 1, 'new' => null]])], null, 'does not exist'],
]);

it('builds changes from stored rows and validates them', function (): void {
    $change = EntityChange::fromStored(3, 'update', rptUtc('2026-09-01'), ['status' => ['old' => 'open', 'new' => 'pending'], 'team_id' => ['new' => 't1']]);

    expect($change->operation)->toBe(ChangeOperation::Update)
        ->and($change->changes)->toBe(['status' => ['old' => 'open', 'new' => 'pending'], 'team_id' => ['old' => null, 'new' => 't1']])
        ->and(fn () => EntityChange::fromStored(1, 'truncate', rptUtc('2026-09-01'), []))->toThrow(InvalidHistory::class, 'Unknown change operation')
        ->and(fn () => new EntityChange(0, ChangeOperation::Insert, rptUtc('2026-09-01'), []))->toThrow(InvalidHistory::class, 'starts at 1');
});

/**
 * One random history: an insert, up to 25 effective updates (some at the same instant) and sometimes a delete.
 *
 * @return array{list<EntityChange>, array<int, array<string, mixed>|null>, array<string, mixed>|null}
 */
function rptRandomHistory(Randomizer $random): array
{
    $values = ['a', 'b', 'c', null];
    $pick = fn (): ?string => $values[$random->getInt(0, 3)];
    $time = rptUtc('2026-09-01 08:00');
    $row = ['status' => $pick(), 'priority' => $pick(), 'assignee_id' => $pick(), 'team_id' => $pick()];
    $changes = [rptInsert(1, $time->toDateTimeString(), $row)];
    $expected = [$time->getTimestamp() => $row];

    for ($step = $random->getInt(1, 25); $step > 0; $step--) {
        $time = $time->addSeconds($random->getInt(0, 3) * 900);   // 0 s: several changes at one instant
        $diff = [];

        foreach (array_keys($row) as $attribute) {
            $value = $pick();

            if ($random->getInt(0, 1) === 1 && $value !== $row[$attribute]) {
                $diff[$attribute] = ['old' => $row[$attribute], 'new' => $value];
                $row[$attribute] = $value;
            }
        }

        if ($diff !== []) {   // the trigger writes nothing for a no-op update
            $changes[] = new EntityChange(count($changes) + 1, ChangeOperation::Update, $time, $diff);
            $expected[$time->getTimestamp()] = $row;   // the last saved row at an instant is the state as of it
        }
    }

    if ($random->getInt(0, 3) === 0) {
        $time = $time->addHour();
        $changes[] = new EntityChange(count($changes) + 1, ChangeOperation::Delete, $time, array_map(fn (?string $v): array => ['old' => $v, 'new' => null], $row));
        $expected[$time->getTimestamp()] = null;
        $row = null;
    }

    return [$random->shuffleArray($changes), $expected, $row];
}

it('agrees with the saved rows and with forward replay on 500 seeded random histories', function (): void {
    $replayer = new ChangeReplayer;
    $checked = 0;

    for ($seed = 1; $seed <= 500; $seed++) {
        [$changes, $expected, $current] = rptRandomHistory(new Randomizer(new Mt19937($seed)));

        foreach ($expected as $timestamp => $state) {
            $at = CarbonImmutable::createFromTimestamp($timestamp, 'UTC');

            expect($replayer->asOf($current, $changes, $at))->toBe($state, "seed {$seed}")
                ->and($replayer->forwardTo($changes, $at))->toBe($state, "seed {$seed}");

            foreach ([-1, 1, 450] as $offset) {
                $near = $at->addSeconds($offset);
                expect($replayer->asOf($current, $changes, $near))->toBe($replayer->forwardTo($changes, $near), "seed {$seed}");
            }

            $checked++;
        }

        expect($replayer->asOf($current, $changes, rptUtc('2026-08-31')))->toBeNull()
            ->and($replayer->asOf($current, $changes, rptUtc('2027-01-01')))->toBe($current);
    }

    expect($checked)->toBeGreaterThan(2000);
});

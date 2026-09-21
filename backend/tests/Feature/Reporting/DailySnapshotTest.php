<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Intervals\IntervalBuilder;
use App\Modules\Reporting\Domain\Intervals\IntervalMeasures;
use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Modules\Reporting\Support\TicketTimeline;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// report_daily_snapshots (docs/05-algorithms/history-and-time-analytics.md §4–5).

require_once __DIR__.'/ReportingHelpers.php';

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('snapshots', ['timezone' => 'Asia/Kathmandu']);
    // Raw reads in the test body see this workspace's rows only (row-level security).
    tenancy()->initialize($this->tenant);
});

/** @return array<string, array<string, int>> dimension => key => backlog, counted ticket by ticket in PHP */
function bruteForceBacklog(Tenant $tenant, array $tickets, CarbonImmutable $end): array
{
    return $tenant->run(function () use ($tickets, $end): array {
        $counts = [];
        foreach ($tickets as $ticket) {
            $fresh = Ticket::query()->findOrFail($ticket->id);
            $timeline = TicketTimeline::for($fresh);
            $intervals = app(IntervalBuilder::class)->build($timeline->initial, $timeline->createdAt, $timeline->events, new TwentyFourSevenCalendar);
            $state = IntervalMeasures::at($intervals, $end->subMicrosecond());
            if ($state === null || in_array($state->get('status'), ['resolved', 'closed'], true)) {
                continue;
            }
            foreach (['none' => '-', 'team' => $state->get('team_id') ?? '-', 'status' => $state->get('status')] as $dimension => $key) {
                $counts[$dimension][(string) $key] = ($counts[$dimension][(string) $key] ?? 0) + 1;
            }
        }

        return $counts;
    });
}

it('counts the backlog at the end of each day exactly like a ticket-by-ticket brute force', function (): void {
    $tickets = randomHistories($this->tenant, 40, 20260921);
    $builder = app(DailySnapshotBuilder::class);

    for ($day = CarbonImmutable::parse('2026-09-01'); $day->lessThanOrEqualTo('2026-09-12'); $day = $day->addDay()) {
        [, $end] = $builder->bounds($day, 'Asia/Kathmandu');
        $rows = $this->tenant->run(fn () => $builder->compute($this->tenant->id, $day, 'Asia/Kathmandu'));
        $stored = [];
        foreach ($rows as $row) {
            if (in_array($row['dimension'], ['none', 'team', 'status'], true) && $row['metrics']['backlog'] > 0) {
                $stored[$row['dimension']][$row['dimension_key']] = $row['metrics']['backlog'];
            }
        }
        $expected = bruteForceBacklog($this->tenant, $tickets, $end);
        foreach ([&$stored, &$expected] as &$side) {
            ksort($side);
            foreach ($side as &$keys) {
                ksort($keys);
            }
        }

        expect($stored)->toBe($expected, "backlog on {$day->toDateString()}");
    }
});

it('counts the day\'s flows under the value the ticket had at that moment', function (): void {
    $tickets = randomHistories($this->tenant, 25, 7);
    $day = CarbonImmutable::parse('2026-09-04');
    [$start, $end] = app(DailySnapshotBuilder::class)->bounds($day, 'Asia/Kathmandu');

    $rows = $this->tenant->run(fn () => app(DailySnapshotBuilder::class)->compute($this->tenant->id, $day, 'Asia/Kathmandu'));
    $none = collect($rows)->firstWhere('dimension', 'none')['metrics'] ?? [];

    $created = collect($tickets)->filter(fn (Ticket $t): bool => $t->created_at >= $start && $t->created_at < $end)->count();
    $events = TicketEvent::query()->withoutTenancy()->where('created_at', '>=', $start)->where('created_at', '<', $end);
    expect($none['created'] ?? 0)->toBe($created)
        ->and($none['resolved'] ?? 0)->toBe((clone $events)->where('type', 'status_changed')->where('new_values->status', 'resolved')->count())
        ->and($none['reopened'] ?? 0)->toBe((clone $events)->where('type', 'reopened')->count());
    // Every flow is placed under exactly one value of every dimension.
    foreach (['team', 'agent', 'priority', 'status'] as $dimension) {
        $sum = collect($rows)->where('dimension', $dimension)->sum(fn (array $row): int => $row['metrics']['created']);
        expect($sum)->toBe($none['created'] ?? 0, $dimension);
    }
});

it('writes the day idempotently and replaces rows that no longer apply', function (): void {
    randomHistories($this->tenant, 10, 3);
    $builder = app(DailySnapshotBuilder::class);
    $day = CarbonImmutable::parse('2026-09-05');
    $now = CarbonImmutable::parse('2026-09-21 06:00:00');

    $first = $this->tenant->run(fn () => $builder->write($this->tenant->id, $day, 'Asia/Kathmandu', $now));
    DB::table('report_daily_snapshots')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id,
        'day' => '2026-09-05', 'dimension' => 'team', 'dimension_key' => 'stale', 'metrics' => '{}', 'computed_at' => $now]);
    $second = $this->tenant->run(fn () => $builder->write($this->tenant->id, $day, 'Asia/Kathmandu', $now));

    expect($second)->toBe($first)
        ->and(DB::table('report_daily_snapshots')->where('day', '2026-09-05')->count())->toBe($first)
        ->and(DB::table('report_daily_snapshots')->where('dimension_key', 'stale')->exists())->toBeFalse();
});

it('uses local days, including a 23-hour daylight-saving day', function (): void {
    $builder = app(DailySnapshotBuilder::class);

    [$start, $end] = $builder->bounds(CarbonImmutable::parse('2026-09-21'), 'Asia/Kathmandu');
    expect($start->toIso8601ZuluString())->toBe('2026-09-20T18:15:00Z')->and($end->toIso8601ZuluString())->toBe('2026-09-21T18:15:00Z');

    [$start, $end] = $builder->bounds(CarbonImmutable::parse('2026-03-29'), 'Europe/London');
    expect($end->getTimestamp() - $start->getTimestamp())->toBe(23 * 3600);
});

it('snapshots each workspace\'s local yesterday, or the given day, and rebuild fills every day', function (): void {
    randomHistories($this->tenant, 8, 11);
    $other = createTenant('snapshots-utc', ['timezone' => 'UTC']);

    Artisan::call('reports:snapshot-daily');
    // 06:00 UTC is 11:45 in Kathmandu: yesterday there is 20 September.
    expect(DB::table('report_daily_snapshots')->where('tenant_id', $this->tenant->id)->distinct()->pluck('day')->all())->toBe(['2026-09-20'])
        // A workspace without tickets has nothing to report on that day.
        ->and(DB::table('report_daily_snapshots')->where('tenant_id', $other->id)->count())->toBe(0);

    Artisan::call('reports:snapshot-daily', ['--tenant' => 'snapshots', '--day' => '2026-09-03']);
    expect(DB::table('report_daily_snapshots')->where('tenant_id', $this->tenant->id)->where('day', '2026-09-03')->exists())->toBeTrue();

    Artisan::call('reports:rebuild', ['--tenant' => 'snapshots']);
    $days = DB::table('report_daily_snapshots')->where('tenant_id', $this->tenant->id)->distinct()->orderBy('day')->pluck('day');
    $firstTicket = CarbonImmutable::parse((string) Ticket::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->min('created_at'))
        ->setTimezone('Asia/Kathmandu')->toDateString();
    expect($days->first())->toBe($firstTicket)
        ->and($days->last())->toBe('2026-09-20')
        ->and(Artisan::call('reports:verify', ['--tenant' => 'snapshots']))->toBe(0);
});

it('is scheduled hourly on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'reports:snapshot-daily'));

    expect($event?->expression)->toBe('20 * * * *')->and($event?->onOneServer)->toBeTrue();
});

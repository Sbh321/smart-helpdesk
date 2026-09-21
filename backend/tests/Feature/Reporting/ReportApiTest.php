<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Stats\Percentile;
use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ReportingHelpers.php';

// The report catalogue and runner (ADR-0022 §4, roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = randomHistories($this->tenant, 30, 42);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
    // last_30d in Kathmandu on 21 September 11:45: 23 August 00:00 to 22 September 00:00 local.
    [$this->from] = app(DailySnapshotBuilder::class)->bounds(CarbonImmutable::parse('2026-08-23'), 'Asia/Kathmandu');
    [, $this->to] = app(DailySnapshotBuilder::class)->bounds(CarbonImmutable::parse('2026-09-21'), 'Asia/Kathmandu');
});

it('lists the catalogue and describes a report', function (): void {
    $this->getJson('/v1/reports')->assertOk()
        ->assertJsonPath('data.0.key', 'rpt-t01')
        ->assertJsonPath('data.0.group', 'tickets');

    $this->getJson('/v1/reports/rpt-t06')->assertOk()
        ->assertJsonPath('data.default_dimension', 'priority')
        ->assertJsonPath('data.drill_down_to', 'tickets')
        ->assertJsonPath('data.measures.2', ['key' => 'first_response_median', 'label' => 'First response, median (business)', 'unit' => 'seconds']);
    $this->getJson('/v1/reports/rpt-x99')->assertNotFound();
});

it('counts ticket volume exactly like independent SQL', function (): void {
    $data = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d', 'group' => 'day'])->assertOk()->json('data');

    $created = DB::table('tickets')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->count();
    $resolved = DB::table('ticket_events')->where('tenant_id', $this->tenant->id)->where('type', 'status_changed')
        ->where('new_values->status', 'resolved')->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->count();
    $reopened = DB::table('ticket_events')->where('tenant_id', $this->tenant->id)->where('type', 'reopened')
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->count();

    expect($data['totals']['created'])->toBe($created)
        ->and($data['totals']['resolved'])->toBe($resolved)
        ->and($data['totals']['reopened'])->toBe($reopened)
        ->and(array_sum(array_column(array_column($data['rows'], 'values'), 'created')))->toBe($created)
        ->and($data['rows'][0]['key'])->toMatch('/^2026-\d{2}-\d{2}$/')
        ->and($data['parameters']['from'])->toBe('2026-08-22T18:15:00Z');
});

it('reports medians and 90th percentiles that match the facts', function (): void {
    ReportTicketFact::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->update([
        'resolution_business_s' => DB::raw('extract(epoch from (coalesce(resolved_at, created_at) - created_at))::bigint'),
    ]);
    $facts = ReportTicketFact::query()->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->get();
    $values = $facts->pluck('resolution_business_s')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values()->all();

    $data = $this->postJson('/v1/reports/rpt-t06/run', ['period' => 'last_30d', 'measures' => 'tickets,resolution_median,resolution_p90'])->assertOk()->json('data');

    expect($data['totals']['tickets'])->toBe($facts->count())
        ->and(round((float) $data['totals']['resolution_median'], 3))->toBe(round((float) Percentile::median($values), 3))
        ->and(round((float) $data['totals']['resolution_p90'], 3))->toBe(round((float) Percentile::p90($values), 3))
        ->and(array_keys($data['totals']))->toBe(['tickets', 'resolution_median', 'resolution_p90']);
});

it('applies filters, labels the groups and compares with the previous period', function (): void {
    $all = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d', 'group' => 'priority', 'compare' => true])->assertOk()->json('data');
    $names = ['P1' => 'P1 Critical', 'P2' => 'P2 High', 'P3' => 'P3 Normal', 'P4' => 'P4 Low'];
    foreach ($all['rows'] as $row) {
        expect($row['label'])->toBe($names[$row['key']]);
    }
    expect($all['previous'])->toBeNull(); // nothing happened in the 30 days before: suppressed below 5 records

    $first = $all['rows'][0];
    $filtered = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d', 'filter' => ['priority' => $first['key']]])->assertOk()->json('data');
    expect($filtered['totals']['created'])->toBe($first['values']['created']);
    $none = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d', 'filter' => ['team' => 'none', 'priority' => 'P9']])->assertOk()->json('data');
    expect($none['totals']['created'])->toBe(0)->and($none['rows'])->toBe([]);

    $custom = $this->postJson('/v1/reports/rpt-t01/run', ['from' => '2026-09-05', 'to' => '2026-09-20', 'compare' => true])->assertOk()->json('data');
    expect($custom['previous'])->not->toBeNull()
        ->and($custom['previous']['created'])->toBe(DB::table('tickets')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', CarbonImmutable::parse('2026-08-20', 'Asia/Kathmandu')->utc())
        ->where('created_at', '<', CarbonImmutable::parse('2026-09-05', 'Asia/Kathmandu')->utc())->count());
});

it('reads the backlog curve from the daily snapshots', function (): void {
    $this->artisan('reports:rebuild', ['--tenant' => 'catalogue'])->assertSuccessful();

    $data = $this->postJson('/v1/reports/rpt-t02/run', ['from' => '2026-09-01', 'to' => '2026-09-12'])->assertOk()->json('data');
    $last = DB::table('report_daily_snapshots')->where('tenant_id', $this->tenant->id)->where('dimension', 'none')
        ->where('day', '2026-09-12')->value('metrics');

    expect($data['rows'])->toHaveCount(12)
        ->and($data['totals']['end_backlog'])->toBe(json_decode((string) $last, true)['backlog'])
        ->and($data['totals']['peak_backlog'])->toBe(max(array_column(array_column($data['rows'], 'values'), 'peak_backlog')));
});

it('drills down from a number to its tickets', function (): void {
    $data = $this->postJson('/v1/reports/rpt-t06/run', ['period' => 'last_30d', 'group' => 'priority'])->json('data');
    $row = $data['rows'][0];

    $records = $this->getJson('/v1/reports/rpt-t06/records?period=last_30d&group=priority&key='.$row['key'])->assertOk();
    expect($records->json('meta.total'))->toBe($row['values']['tickets'])
        ->and($records->json('meta.entity'))->toBe('tickets')
        ->and($records->json('data.0'))->toHaveKeys(['id', 'entity', 'label', 'subtitle', 'status'])
        ->and($records->json('data.0.label'))->toStartWith('#');
    $this->getJson('/v1/reports/rpt-t02/records?period=last_30d')->assertNotFound();
});

it('rejects parameters the report does not declare', function (array $payload, string $field): void {
    $this->postJson('/v1/reports/rpt-t01/run', $payload)->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'unknown group' => [['group' => 'colour'], 'group'],
    'unknown measure' => [['measures' => 'created,happiness'], 'measures'],
    'unknown filter' => [['filter' => ['mood' => 'happy']], 'filter.mood'],
    'unknown period' => [['period' => 'last_decade'], 'period'],
    'reversed range' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'from'],
    'range too long' => [['from' => '2020-01-01', 'to' => '2026-09-01'], 'from'],
]);

it('keeps other workspaces out of every number', function (): void {
    randomHistories(createTenant('other-catalogue', ['timezone' => 'Asia/Kathmandu']), 20, 7);
    tenancy()->initialize($this->tenant);

    $created = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d'])->json('data.totals.created');
    expect($created)->toBe(DB::table('tickets')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->count());
});

it('needs reports.view and each report\'s own permissions', function (): void {
    actingAsRole($this->tenant, 'developer', createTenantUser($this->tenant)); // reports.view and tickets.view
    tenancy()->initialize($this->tenant);
    $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_7d'])->assertOk();

    $viewer = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($viewer): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $viewer->givePermissionTo('reports.view');
    });
    actingAsTenantUser($this->tenant, $viewer);
    tenancy()->initialize($this->tenant);
    $this->getJson('/v1/reports')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/v1/reports/rpt-t01/run')->assertForbidden();
});

it('serves a repeated run from a serializing cache store', function (): void {
    // The array store used in tests keeps objects as they are; redis (as in production) serializes,
    // and refuse to unserialize application classes.
    config(['cache.default' => 'redis']);
    Cache::flush();

    $first = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d'])->assertOk()->json('data');
    $second = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_30d'])->assertOk()->json('data');

    expect($second)->toBe($first)->and($first['totals']['created'])->toBeGreaterThan(0);
    Cache::flush();
});

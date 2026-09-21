<?php

declare(strict_types=1);

use App\Modules\Identity\Support\PermissionCatalogue;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

require_once __DIR__.'/CatalogueHelpers.php';

// The dashboard (roadmap M3-01, FR-ANL): tiles and series are catalogue report runs.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('dashboard', ['timezone' => 'Asia/Kathmandu']);
    catalogueWorkspace($this->tenant, 40, 7);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
});

it('shows the numbers of the corresponding catalogue report runs', function (): void {
    $data = $this->getJson('/v1/dashboard?period=last_30d')->assertOk()->json('data');

    expect($data['period'])->toBe('last_30d')
        ->and($data['timezone'])->toBe('Asia/Kathmandu')
        ->and($data['from'])->toBe('2026-08-22T18:15:00Z')
        ->and(array_column($data['kpis'], 'key'))->toBe(['created', 'resolved', 'open_now', 'first_response_median', 'resolution_median', 'sla_compliance', 'sla_breaches', 'reopen_rate'])
        ->and(array_column($data['series'], 'key'))->toBe(['volume', 'backlog', 'response_by_priority', 'sla_compliance', 'agent_workload', 'time_in_status']);

    foreach ($data['kpis'] as $kpi) {
        $run = $this->postJson("/v1/reports/{$kpi['report']}/run", ['period' => 'last_30d', 'compare' => true])->assertOk()->json('data');
        expect($kpi['value'])->toEqual($run['totals'][$kpi['measure']], $kpi['key'])
            ->and($kpi['previous'])->toEqual($run['previous'][$kpi['measure']] ?? null, $kpi['key']);
    }
    $created = collect($data['kpis'])->firstWhere('key', 'created');
    expect($created['value'])->toBeGreaterThan(0)->and($created['unit'])->toBe('count');

    foreach ($data['series'] as $series) {
        $run = $this->postJson("/v1/reports/{$series['report']}/run", [
            'period' => 'last_30d', 'group' => $series['parameters']['group'], 'measures' => implode(',', $series['parameters']['measures']),
        ])->assertOk()->json('data');
        expect($series['rows'])->toEqual($run['rows'], $series['key'])
            ->and(array_column($series['measures'], 'key'))->toBe($series['parameters']['measures']);
    }
    expect(array_sum(array_map(fn (array $row): int => (int) $row['values']['created'], $data['series'][0]['rows'])))->toBe($created['value']);
});

it('takes the period from the query and rejects an unknown one', function (): void {
    $week = $this->getJson('/v1/dashboard?period=last_7d')->assertOk()->json('data');
    expect($week['period'])->toBe('last_7d')->and($week['from'])->toBe('2026-09-14T18:15:00Z');
    $run = $this->postJson('/v1/reports/rpt-t01/run', ['period' => 'last_7d'])->assertOk()->json('data');
    expect(collect($week['kpis'])->firstWhere('key', 'created')['value'])->toBe($run['totals']['created']);

    expect($this->getJson('/v1/dashboard')->assertOk()->json('data.period'))->toBe('last_30d');
    $this->getJson('/v1/dashboard?period=forever')->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('leaves out what the caller may not run', function (): void {
    $user = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($user): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $user->givePermissionTo(array_values(array_diff(PermissionCatalogue::roles()[PermissionCatalogue::AGENT], ['agents.view'])));
    });
    actingAsTenantUser($this->tenant, $user);
    tenancy()->initialize($this->tenant);

    $data = $this->getJson('/v1/dashboard')->assertOk()->json('data');
    expect(array_column($data['series'], 'key'))->not->toContain('agent_workload')->toContain('volume')
        ->and(count($data['kpis']))->toBe(8);
});

it('needs reports.view', function (): void {
    $user = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($user): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $user->givePermissionTo(['tickets.view']);
    });
    actingAsTenantUser($this->tenant, $user);

    $this->getJson('/v1/dashboard')->assertForbidden();
});

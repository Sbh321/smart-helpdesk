<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Assignment\FairnessIndex;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CatalogueHelpers.php';

// Agent and team reports RPT-A01 … RPT-A06 (roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-agents', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = catalogueWorkspace($this->tenant, 40, 41);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
    $this->period = ['from' => '2026-08-25', 'to' => '2026-09-21'];
    $this->from = CarbonImmutable::parse('2026-08-25', 'Asia/Kathmandu')->utc();
    $this->to = CarbonImmutable::parse('2026-09-22', 'Asia/Kathmandu')->utc();
    $this->agents = DB::table('agent_profiles')->where('tenant_id', $this->tenant->id)->orderBy('id')->pluck('id')->all();
});

/** The end-of-day `metric` of every agent on one day, 0 for an agent without a snapshot row. */
function agentMetric(string $tenantId, array $agents, string $day, string $metric): array
{
    $rows = DB::table('report_daily_snapshots')->where('tenant_id', $tenantId)->where('day', $day)->where('dimension', 'agent')
        ->pluck('metrics', 'dimension_key')->map(fn (string $json): array => json_decode($json, true));

    return array_map(fn (string $agent): float => (float) ($rows[$agent][$metric] ?? 0), $agents);
}

it('sums the agents\' end-of-day backlog per day (RPT-A01)', function (): void {
    $data = $this->postJson('/v1/reports/rpt-a01/run', $this->period)->assertOk()->json('data');
    $day = collect($data['rows'])->firstWhere('key', '2026-09-05');

    expect($data['rows'])->not->toBeEmpty()
        ->and((float) $day['values']['backlog'])->toBe(array_sum(agentMetric($this->tenant->id, $this->agents, '2026-09-05', 'backlog')))
        ->and(collect($data['rows'])->pluck('key')->max())->toBe('2026-09-20'); // snapshots stop at yesterday

    $byAgent = $this->postJson('/v1/reports/rpt-a01/run', [...$this->period, 'group' => 'agent'])->assertOk()->json('data');
    expect(array_column($byAgent['rows'], 'key'))->each->toBeIn($this->agents);
});

it('reports agent performance from the facts (RPT-A02)', function (): void {
    $facts = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->get();

    $totals = reportTotals('rpt-a02', $this->period);

    expect($totals['assigned'])->toBe($facts->whereNotNull('assigned_agent_id')->count())
        ->and($totals['resolved'])->toBe($facts->whereNotNull('resolved_at')->count());
});

it('computes Jain\'s index per day like FairnessIndex (RPT-A03)', function (): void {
    $data = $this->postJson('/v1/reports/rpt-a03/run', $this->period)->assertOk()->json('data');
    $row = collect($data['rows'])->firstWhere('key', '2026-09-05');
    $loads = agentMetric($this->tenant->id, $this->agents, '2026-09-05', 'load');

    expect(round((float) $row['values']['jain'], 4))->toBe(round(FairnessIndex::jain($loads), 4))
        ->and(round((float) $row['values']['cv'], 4))->toBe(round(FairnessIndex::coefficientOfVariation($loads), 4))
        ->and((float) $row['values']['agents'])->toBe((float) count($this->agents));
});

it('sums scheduled shift hours and finds automatic assignments outside shifts (RPT-A04)', function (): void {
    // The first agent works Mondays 09:00–17:00; the period has four Mondays (31 Aug, 7, 14 and 21 Sep).
    $agent = DB::table('agent_shifts')->where('tenant_id', $this->tenant->id)->value('agent_profile_id');
    $outside = DB::table('ticket_assignments')->where('tenant_id', $this->tenant->id)->where('agent_profile_id', $agent)->where('reason', 'auto')
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->pluck('created_at')
        ->filter(function (string $at): bool {
            $local = CarbonImmutable::parse($at, 'UTC')->setTimezone('Asia/Kathmandu');

            return ! ($local->dayOfWeek === 1 && $local->format('H:i:s') >= '09:00:00' && $local->format('H:i:s') < '17:00:00');
        })->count();

    $data = $this->postJson('/v1/reports/rpt-a04/run', $this->period)->assertOk()->json('data');
    $row = collect($data['rows'])->firstWhere('key', $agent);

    expect((float) $row['values']['shift_hours'])->toBe(32.0)
        ->and($row['values']['outside_shift'])->toBe($outside)
        ->and($data['totals']['outside_shift'])->toBe($outside) // agents without a shift plan never count
        ->and($data['totals']['automatic'])->toBe(countBetween('ticket_assignments', $this->tenant->id, 'created_at', $this->from, $this->to, fn ($q) => $q->where('reason', 'auto')));
});

it('compares teams and covers skills (RPT-A05, RPT-A06)', function (): void {
    $teams = $this->postJson('/v1/reports/rpt-a05/run', $this->period)->assertOk()->json('data');
    expect($teams['totals']['tickets'])->toBe(countBetween('report_ticket_facts', $this->tenant->id, 'created_at', $this->from, $this->to))
        ->and(array_sum(array_column(array_column($teams['rows'], 'values'), 'tickets')))->toBe($teams['totals']['tickets']);

    $needing = DB::table('tickets as t')->join('category_skill as cs', 'cs.category_id', '=', 't.category_id')
        ->where('t.tenant_id', $this->tenant->id)->where('t.created_at', '>=', $this->from)->where('t.created_at', '<', $this->to)->count();
    $skills = $this->postJson('/v1/reports/rpt-a06/run', $this->period)->assertOk()->json('data');
    expect($skills['rows'])->toHaveCount(1)
        ->and($skills['rows'][0]['values']['agents'])->toBe(1)
        ->and($skills['rows'][0]['values']['agents_level_4_5'])->toBe(1)
        ->and($skills['rows'][0]['values']['tickets'])->toBe($needing)->toBeGreaterThan(0);
});

it('needs agents.view for the agent reports', function (): void {
    $user = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($user): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $user->givePermissionTo(['reports.view', 'tickets.view']);
    });
    actingAsTenantUser($this->tenant, $user);
    tenancy()->initialize($this->tenant);

    expect(array_column($this->getJson('/v1/reports')->assertOk()->json('data'), 'group'))->not->toContain('agents');
    $this->postJson('/v1/reports/rpt-a02/run')->assertForbidden();
    $this->postJson('/v1/reports/rpt-a03/run')->assertForbidden();
});

it('keeps other workspaces out of the agent reports', function (): void {
    catalogueWorkspace(createTenant('other-agents', ['timezone' => 'Asia/Kathmandu']), 10, 8);
    tenancy()->initialize($this->tenant);

    $data = $this->postJson('/v1/reports/rpt-a03/run', $this->period)->assertOk()->json('data');
    expect((float) collect($data['rows'])->firstWhere('key', '2026-09-05')['values']['agents'])->toBe((float) count($this->agents))
        ->and(reportTotals('rpt-a04', $this->period)['shift_hours'])->toEqual(32);
});

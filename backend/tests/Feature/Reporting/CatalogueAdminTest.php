<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Reporting\Reports\Catalogue\ConfigurationHistory;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CatalogueHelpers.php';

// Media and administration reports RPT-M01, RPT-G01, RPT-G02 (roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-admin', ['timezone' => 'Asia/Kathmandu']);
    catalogueWorkspace($this->tenant, 20, 61);
    actingAsRole($this->tenant, 'admin');
    tenancy()->initialize($this->tenant);
    $this->period = ['from' => '2026-09-01', 'to' => '2026-09-10'];
    $this->from = CarbonImmutable::parse('2026-09-01', 'Asia/Kathmandu')->utc();
    $this->to = CarbonImmutable::parse('2026-09-11', 'Asia/Kathmandu')->utc();
});

it('sums the storage of completed uploads by type (RPT-M01)', function (): void {
    $data = $this->postJson('/v1/reports/rpt-m01/run', $this->period)->assertOk()->json('data');

    expect($data['totals'])->toBe(['items' => 2, 'bytes' => 2148, 'trashed' => 0]) // the pending upload does not count
        ->and(array_column($data['rows'], 'key'))->toBe(['image', 'text']) // largest first
        ->and($data['rows'][0]['values']['bytes'])->toBe(2048);
});

it('counts audit actions and access changes (RPT-G01)', function (): void {
    $count = fn (?Closure $where = null): int => countBetween('audit_logs', $this->tenant->id, 'created_at', $this->from, $this->to, $where);

    $data = $this->postJson('/v1/reports/rpt-g01/run', $this->period)->assertOk()->json('data');

    expect($data['totals']['actions'])->toBe($count())->toBe(4)
        ->and($data['totals']['access_changes'])->toBe(2)
        ->and($data['rows'][0])->toMatchArray(['key' => 'settings.updated', 'values' => ['actions' => 2, 'actors' => 0, 'access_changes' => 0]]);
});

it('counts configuration changes from change capture (RPT-G02)', function (): void {
    $today = CarbonImmutable::now('Asia/Kathmandu');
    $period = ['from' => $today->subDays(2)->toDateString(), 'to' => $today->addDay()->toDateString()];
    $changes = DB::table('entity_changes')->where('tenant_id', $this->tenant->id)->whereIn('entity_type', ConfigurationHistory::ENTITY_TYPES)
        ->where('occurred_at', '>=', CarbonImmutable::parse($period['from'], 'Asia/Kathmandu')->utc())
        ->where('occurred_at', '<', CarbonImmutable::parse($period['to'], 'Asia/Kathmandu')->addDay()->utc())->get();

    $data = $this->postJson('/v1/reports/rpt-g02/run', $period)->assertOk()->json('data');
    $labels = array_column($data['rows'], 'label', 'key');

    expect($data['totals']['changes'])->toBe($changes->count())->toBeGreaterThan(0)
        ->and($data['totals']['records'])->toBe($changes->unique('entity_id')->count())
        ->and($labels['sla_policies'] ?? null)->toBe('SLA policies')
        ->and($labels)->toHaveKey('skills');
});

it('needs media.view, audit.view and settings.manage for these reports', function (): void {
    actingAsRole($this->tenant, 'manager', createTenantUser($this->tenant)); // no audit.view, no settings.manage
    tenancy()->initialize($this->tenant);
    $keys = array_column($this->getJson('/v1/reports')->assertOk()->json('data'), 'key');

    expect($keys)->toContain('rpt-m01')->not->toContain('rpt-g01')->not->toContain('rpt-g02');
    $this->postJson('/v1/reports/rpt-g01/run')->assertForbidden();
    $this->postJson('/v1/reports/rpt-g02/run')->assertForbidden();

    $user = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($user): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $user->givePermissionTo(['reports.view', 'tickets.view']);
    });
    actingAsTenantUser($this->tenant, $user);
    tenancy()->initialize($this->tenant);
    $this->postJson('/v1/reports/rpt-m01/run')->assertForbidden();
});

it('keeps other workspaces out of the administration reports', function (): void {
    $other = createTenant('other-admin', ['timezone' => 'Asia/Kathmandu']);
    $other->run(fn () => AuditLog::query()->create(['tenant_id' => $other->id, 'actor_type' => 'user', 'actor_id' => null,
        'action' => 'settings.updated', 'changes' => [], 'created_at' => '2026-09-03 08:00:00']));
    tenancy()->initialize($this->tenant);

    expect(reportTotals('rpt-g01', $this->period)['actions'])->toBe(4)
        ->and(reportTotals('rpt-m01', $this->period)['items'])->toBe(2);
});

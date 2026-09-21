<?php

declare(strict_types=1);

use App\Modules\Identity\Support\PermissionCatalogue;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CatalogueHelpers.php';

// Contact and organisation reports RPT-C01 … RPT-C04 (roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-contacts', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = catalogueWorkspace($this->tenant, 40, 31);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
    $this->period = ['from' => '2026-08-25', 'to' => '2026-09-21'];
    $this->from = CarbonImmutable::parse('2026-08-25', 'Asia/Kathmandu')->utc();
    $this->to = CarbonImmutable::parse('2026-09-22', 'Asia/Kathmandu')->utc();
    // Change capture stamps rows with the database clock, so its reports are asked about the real days.
    $today = CarbonImmutable::now('Asia/Kathmandu');
    $this->capturePeriod = ['from' => $today->subDays(3)->toDateString(), 'to' => $today->addDay()->toDateString()];
});

it('counts new and active contacts (RPT-C01)', function (): void {
    $active = DB::table('tickets')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->distinct()->count('contact_id');

    $data = $this->postJson('/v1/reports/rpt-c01/run', [...$this->period, 'group' => 'organization'])->assertOk()->json('data');

    expect($data['totals']['new_contacts'])->toBe(countBetween('contacts', $this->tenant->id, 'created_at', $this->from, $this->to))
        ->and($data['totals']['active_contacts'])->toBe($active)->toBeGreaterThan(0)
        ->and(array_column($data['rows'], 'label'))->toContain('None');
});

it('ranks the top requesters by tickets (RPT-C02)', function (): void {
    $facts = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->get();

    $data = $this->postJson('/v1/reports/rpt-c02/run', [...$this->period, 'group' => 'organization'])->assertOk()->json('data');
    $counts = array_column(array_column($data['rows'], 'values'), 'tickets');

    expect($data['totals']['tickets'])->toBe($facts->count())
        ->and($data['totals']['breaches'])->toBe($facts->filter(fn (object $f): bool => $f->first_response_sla === 'breached' || $f->resolution_sla === 'breached')->count())
        ->and($counts)->toBe(collect($counts)->sortDesc()->values()->all());
});

it('computes SLA compliance per tier from the facts (RPT-C03)', function (): void {
    $facts = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->get();
    $met = $facts->where('first_response_sla', 'met')->count() + $facts->where('resolution_sla', 'met')->count();
    $decided = $met + $facts->where('first_response_sla', 'breached')->count() + $facts->where('resolution_sla', 'breached')->count();

    $data = $this->postJson('/v1/reports/rpt-c03/run', $this->period)->assertOk()->json('data');

    expect($data['totals']['sla_compliance'])->toEqual($decided === 0 ? null : round(100 * $met / $decided, 1))
        ->and($data['totals']['open'])->toBe($facts->whereNull('resolved_at')->whereNull('closed_at')->count())
        ->and(array_column($data['rows'], 'label'))->toContain('Standard', 'Enterprise'); // the premium organisation moved to enterprise
});

it('reads tier changes and contact moves from change capture (RPT-C04)', function (): void {
    $from = CarbonImmutable::parse($this->capturePeriod['from'], 'Asia/Kathmandu')->utc();
    $to = CarbonImmutable::parse($this->capturePeriod['to'], 'Asia/Kathmandu')->addDay()->utc();
    $added = DB::table('entity_changes')->where('tenant_id', $this->tenant->id)->where('entity_type', 'contacts')
        ->whereIn('operation', ['insert', 'update'])->whereNotNull(DB::raw("changes -> 'organization_id' ->> 'new'"))
        ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->count();

    $data = $this->postJson('/v1/reports/rpt-c04/run', $this->capturePeriod)->assertOk()->json('data');

    expect($data['totals']['tier_changes'])->toBe(1)
        ->and($data['totals']['contacts_added'])->toBe($added)->toBeGreaterThan(0)
        ->and($data['totals']['net_contacts'])->toBe($added - $data['totals']['contacts_removed']);
});

it('hides contact reports from a user without contacts.view', function (): void {
    $user = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($user): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $user->givePermissionTo(array_values(array_diff(PermissionCatalogue::roles()[PermissionCatalogue::AGENT], ['contacts.view', 'contacts.manage'])));
    });
    actingAsTenantUser($this->tenant, $user);
    tenancy()->initialize($this->tenant);

    $groups = array_column($this->getJson('/v1/reports')->assertOk()->json('data'), 'group');
    expect($groups)->not->toContain('contacts')->and($groups)->toContain('tickets');
    foreach (['rpt-c01', 'rpt-c02', 'rpt-c03', 'rpt-c04'] as $key) {
        $this->postJson("/v1/reports/{$key}/run")->assertForbidden();
    }
});

it('keeps other workspaces out of the contact reports', function (): void {
    randomHistories(createTenant('other-contacts', ['timezone' => 'Asia/Kathmandu']), 15, 6);
    tenancy()->initialize($this->tenant);

    expect(reportTotals('rpt-c01', $this->period)['active_contacts'])->toBe(DB::table('tickets')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->distinct()->count('contact_id'))
        ->and(reportTotals('rpt-c02', $this->period)['tickets'])->toBe(countBetween('report_ticket_facts', $this->tenant->id, 'created_at', $this->from, $this->to));
});

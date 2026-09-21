<?php

declare(strict_types=1);

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CatalogueHelpers.php';

// SLA reports RPT-S01 … RPT-S04 (roadmap M3-02).

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-sla', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = catalogueWorkspace($this->tenant, 40, 51);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
    $this->period = ['from' => '2026-08-25', 'to' => '2026-09-21'];
    $this->from = CarbonImmutable::parse('2026-08-25', 'Asia/Kathmandu')->utc();
    $this->to = CarbonImmutable::parse('2026-09-22', 'Asia/Kathmandu')->utc();
    $this->now = CarbonImmutable::parse('2026-09-21 06:00:00');
});

/** A timer in warning now: due in 30 minutes. */
function timerAtRisk(Tenant $tenant, Ticket $ticket): TicketSlaTimer
{
    return $tenant->run(fn (): TicketSlaTimer => TicketSlaTimer::factory()->create([
        'ticket_id' => $ticket->id, 'policy_id' => SlaPolicy::factory()->create()->id, 'kind' => 'resolution', 'state' => 'warning',
        'started_at' => '2026-09-21 05:00:00', 'warning_at' => '2026-09-21 05:45:00', 'due_at' => '2026-09-21 06:30:00',
    ]));
}

it('counts met and breached timers and the compliance rate (RPT-S01)', function (): void {
    $timers = DB::table('ticket_sla_timers')->where('tenant_id', $this->tenant->id)
        ->where('started_at', '>=', $this->from)->where('started_at', '<', $this->to)->get();
    $met = $timers->where('state', 'met')->whereNull('breached_at')->count();
    $breached = $timers->whereNotNull('breached_at')->count();

    $data = $this->postJson('/v1/reports/rpt-s01/run', [...$this->period, 'group' => 'kind'])->assertOk()->json('data');

    expect($data['totals']['timers'])->toBe($timers->count())
        ->and($data['totals']['met'])->toBe($met)->toBeGreaterThan(0)
        ->and($data['totals']['breached'])->toBe($breached)->toBeGreaterThan(0)
        ->and($data['totals']['compliance'])->toEqual(round(100 * $met / ($met + $breached), 1))
        ->and(array_column($data['rows'], 'label'))->toBe(['First response', 'Resolution']);
});

it('measures how late the breaches were, up to now while they run (RPT-S02)', function (): void {
    $breaches = DB::table('ticket_sla_timers')->where('tenant_id', $this->tenant->id)->whereNotNull('breached_at')
        ->where('breached_at', '>=', $this->from)->where('breached_at', '<', $this->to)->get();
    $lateness = $breaches->map(fn (object $t): int => max(0, CarbonImmutable::parse($t->met_at ?? $t->cancelled_at ?? $this->now)->getTimestamp()
        - CarbonImmutable::parse($t->due_at)->getTimestamp()));

    $data = $this->postJson('/v1/reports/rpt-s02/run', $this->period)->assertOk()->json('data');

    expect($data['totals']['breaches'])->toBe($breaches->count())
        ->and(round((float) $data['totals']['average_lateness']))->toBe(round($lateness->avg()))
        ->and(array_column($data['rows'], 'label'))->each->toBeIn(['No first response in time', 'Late resolution']);
});

it('computes the share of SLA time paused (RPT-S03)', function (): void {
    $timers = DB::table('ticket_sla_timers')->where('tenant_id', $this->tenant->id)->where('kind', 'resolution')
        ->where('started_at', '>=', $this->from)->where('started_at', '<', $this->to)->get();
    $elapsed = $timers->sum(fn (object $t): int => max(0, CarbonImmutable::parse($t->met_at ?? $t->cancelled_at ?? $this->now)->getTimestamp()
        - CarbonImmutable::parse($t->started_at)->getTimestamp()));

    $totals = reportTotals('rpt-s03', $this->period);

    expect($totals['timers'])->toBe($timers->count())
        ->and($totals['paused_timers'])->toBe($timers->where('paused_total_seconds', '>', 0)->count())
        ->and((float) $totals['paused_share'])->toBe(round(100 * $timers->sum('paused_total_seconds') / $elapsed, 1));
});

it('lists the timers at risk now with the time remaining, whatever the period (RPT-S04)', function (): void {
    $ticket = $this->tickets[0];
    timerAtRisk($this->tenant, $ticket);

    $data = $this->postJson('/v1/reports/rpt-s04/run', ['period' => 'last_month'])->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0]['key'])->toBe($ticket->id)
        ->and($data['rows'][0]['label'])->toBe("#{$ticket->number} {$ticket->title}")
        ->and((int) $data['rows'][0]['values']['remaining'])->toBe(1800)
        ->and($data['totals']['timers'])->toBe(1);
    $this->getJson('/v1/reports/rpt-s04/records')->assertOk()->assertJsonPath('meta.total', 1);
});

it('keeps other workspaces out of the SLA reports', function (): void {
    $other = createTenant('other-sla', ['timezone' => 'Asia/Kathmandu']);
    timerAtRisk($other, $other->run(fn (): Ticket => Ticket::factory()->create()));
    tenancy()->initialize($this->tenant);

    expect(reportTotals('rpt-s04')['timers'])->toBe(0)
        ->and(reportTotals('rpt-s01', $this->period)['timers'])->toBe(countBetween('ticket_sla_timers', $this->tenant->id, 'started_at', $this->from, $this->to));
});

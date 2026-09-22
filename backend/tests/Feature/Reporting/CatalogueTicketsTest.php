<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Stats\Percentile;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CatalogueHelpers.php';

// Ticket reports RPT-T03 … RPT-T11 (roadmap M3-02), each checked against an independent computation.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('catalogue-tickets', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = catalogueWorkspace($this->tenant, 40, 21);
    actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
    $this->period = ['from' => '2026-08-25', 'to' => '2026-09-21'];
    $this->from = CarbonImmutable::parse('2026-08-25', 'Asia/Kathmandu')->utc();
    $this->to = CarbonImmutable::parse('2026-09-22', 'Asia/Kathmandu')->utc();
});

it('measures time in status like the intervals, open ones up to now (RPT-T03)', function (): void {
    $now = CarbonImmutable::parse('2026-09-21 06:00:00');
    $intervals = DB::table('report_ticket_intervals')->where('tenant_id', $this->tenant->id)
        ->where('starts_at', '>=', $this->from)->where('starts_at', '<', $this->to)->where('status', 'in_progress')->get();
    $wall = $intervals->map(fn (object $i): float => (float) ($i->wall_seconds
        ?? max(0, $now->getTimestamp() - CarbonImmutable::parse($i->starts_at)->getTimestamp())))->all();

    $data = $this->postJson('/v1/reports/rpt-t03/run', [...$this->period, 'filter' => ['status' => 'in_progress']])->assertOk()->json('data');

    expect($intervals->count())->toBeGreaterThan(0)
        ->and($data['totals']['intervals'])->toBe($intervals->count())
        ->and($data['totals']['open'])->toBe($intervals->whereNull('ends_at')->count())
        ->and(round((float) $data['totals']['median_wall'], 3))->toBe(round((float) Percentile::median($wall), 3))
        ->and($data['rows'][0]['label'])->toBe('In progress');
});

it('counts status transitions and rework loops from the events (RPT-T04)', function (): void {
    $events = DB::table('ticket_events')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)
        ->whereNotNull(DB::raw("old_values ->> 'status'"))->whereNotNull(DB::raw("new_values ->> 'status'"))
        ->whereRaw("old_values ->> 'status' <> new_values ->> 'status'")->get();
    $reopens = DB::table('ticket_events')->where('tenant_id', $this->tenant->id)->where('type', 'reopened')
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->count();

    $data = $this->postJson('/v1/reports/rpt-t04/run', $this->period)->assertOk()->json('data');
    $labels = array_column($data['rows'], 'label', 'key');

    expect($data['totals']['transitions'])->toBe($events->count())
        ->and($data['totals']['rework'])->toBe($reopens)
        ->and((float) $data['totals']['share'])->toBe(100.0)
        ->and($labels['open → assigned'] ?? null)->toBe('Open → Assigned')
        ->and(array_sum(array_column(array_column($data['rows'], 'values'), 'transitions')))->toBe($events->count());
});

it('ages the open tickets now, whatever the period (RPT-T05)', function (): void {
    $open = DB::table('tickets')->where('tenant_id', $this->tenant->id)->whereNotIn('status', ['resolved', 'closed'])->get();
    $oldest = CarbonImmutable::parse('2026-09-21 06:00:00')->getTimestamp() - CarbonImmutable::parse($open->min('created_at'))->getTimestamp();

    $data = $this->postJson('/v1/reports/rpt-t05/run', ['period' => 'yesterday', 'compare' => true])->assertOk()->json('data');

    expect($data['totals']['open'])->toBe($open->count())
        ->and((int) round((float) $data['totals']['oldest']))->toBe($oldest)
        ->and($data['previous'])->toBeNull()
        ->and(array_sum(array_column(array_column($data['rows'], 'values'), 'open')))->toBe($open->count())
        ->and(array_column($data['rows'], 'label'))->each->toBeIn(['Under 1 day', '1–3 days', '3–7 days', '7–30 days', 'Over 30 days']);
});

it('computes the reopen rate with its denominator (RPT-T07)', function (): void {
    $facts = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->get();
    $reopened = $facts->where('reopen_count', '>', 0)->count();
    $resolved = $facts->filter(fn (object $f): bool => $f->resolved_at !== null || $f->reopen_count > 0)->count();

    $totals = reportTotals('rpt-t07', $this->period);

    expect($totals['reopened'])->toBe($reopened)
        ->and($totals['resolved'])->toBe($resolved)
        ->and($totals['reopen_rate'])->toEqual($resolved === 0 ? null : round(100 * $reopened / $resolved, 1));
});

it('counts assignments by reason and runs without an eligible agent (RPT-T08)', function (): void {
    $count = fn (?Closure $where = null): int => countBetween('ticket_assignments', $this->tenant->id, 'created_at', $this->from, $this->to, $where);

    $data = $this->postJson('/v1/reports/rpt-t08/run', $this->period)->assertOk()->json('data');

    expect($data['totals']['assignments'])->toBe($count())
        ->and($data['totals']['reassignments'])->toBe($count(fn ($q) => $q->where('reason', 'reassign')))
        ->and($data['totals']['no_eligible_agent'])->toBe($count(fn ($q) => $q->where('explanation->outcome', 'no_eligible_agent')))
        ->and(array_column($data['rows'], 'label'))->toContain('Automatic', 'Manual', 'Reassignment');
});

it('reports priority scores and overrides of the tickets created (RPT-T09)', function (): void {
    $ids = DB::table('report_ticket_facts')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)->pluck('ticket_id');
    $average = (float) DB::table('tickets')->whereIn('id', $ids)->avg('priority_score');

    $data = $this->postJson('/v1/reports/rpt-t09/run', [...$this->period, 'group' => 'score'])->assertOk()->json('data');

    expect($data['totals']['tickets'])->toBe($ids->count())
        ->and(round((float) $data['totals']['average_score'], 2))->toBe(round($average, 2))
        ->and($data['totals']['overridden'])->toBe(0)
        ->and($data['rows'][0]['key'])->toMatch('/^\d{2}–\d{2}$/');
});

it('measures duplicate suggestions and the acceptance rate (RPT-T10)', function (): void {
    $count = fn (?string $decision = null): int => countBetween('ticket_duplicate_suggestions', $this->tenant->id, 'created_at', $this->from, $this->to,
        $decision === null ? null : fn ($q) => $q->where('decision', $decision));
    [$accepted, $dismissed] = [$count('accepted'), $count('dismissed')];

    $totals = reportTotals('rpt-t10', $this->period);

    expect($totals['suggestions'])->toBe($count())->toBeGreaterThan(0)
        ->and($totals['accepted'])->toBe($accepted)
        ->and($totals['dismissed'])->toBe($dismissed)
        ->and($totals['acceptance_rate'])->toEqual($accepted + $dismissed === 0 ? null : round(100 * $accepted / ($accepted + $dismissed), 1));
});

it('places tickets and replies on the weekday × hour grid in the workspace zone (RPT-T11)', function (): void {
    $data = $this->postJson('/v1/reports/rpt-t11/run', $this->period)->assertOk()->json('data');
    $first = DB::table('tickets')->where('tenant_id', $this->tenant->id)->orderBy('created_at')->value('created_at');
    $local = CarbonImmutable::parse($first, 'UTC')->setTimezone('Asia/Kathmandu');
    $key = $local->isoWeekday().'-'.$local->format('H');
    $row = collect($data['rows'])->firstWhere('key', $key);

    expect($data['totals']['created'])->toBe(countBetween('tickets', $this->tenant->id, 'created_at', $this->from, $this->to))
        ->and($data['totals']['replies'])->toBe(countBetween('ticket_comments', $this->tenant->id, 'created_at', $this->from, $this->to,
            fn ($q) => $q->where('visibility', 'public')->where('author_type', 'user')))
        ->and($row)->not->toBeNull()
        ->and($row['label'])->toBe($local->format('l H').':00');
});

it('keeps other workspaces out of the ticket reports', function (): void {
    randomHistories(createTenant('other-tickets', ['timezone' => 'Asia/Kathmandu']), 15, 5);
    tenancy()->initialize($this->tenant);

    $open = DB::table('tickets')->where('tenant_id', $this->tenant->id)->whereNotIn('status', ['resolved', 'closed'])->count();
    expect(reportTotals('rpt-t05')['open'])->toBe($open)
        ->and(reportTotals('rpt-t04', $this->period)['transitions'])->toBe(DB::table('ticket_events')->where('tenant_id', $this->tenant->id)
        ->where('created_at', '>=', $this->from)->where('created_at', '<', $this->to)
        ->whereRaw("old_values ->> 'status' <> new_values ->> 'status'")->count());
});

it('drills into the records one count measure counts, not the whole row (RPT-T01)', function (): void {
    $data = $this->postJson('/v1/reports/rpt-t01/run', [...$this->period, 'group' => 'priority'])->assertOk()->json('data');
    $row = collect($data['rows'])->first(fn (array $r): bool => $r['values']['resolved'] > 0 && $r['values']['created'] !== $r['values']['resolved']);
    expect($row)->not->toBeNull();

    $records = fn (array $query): array => $this->getJson('/v1/reports/rpt-t01/records?'.http_build_query([...$this->period, 'group' => 'priority', 'key' => $row['key'], ...$query]))
        ->assertOk()->json();

    // Each ticket is created once, so the created drill-down matches the number exactly.
    expect($records(['measure' => 'created'])['meta']['total'])->toBe($row['values']['created']);

    $resolvedIds = DB::table('ticket_events')->where('ticket_events.tenant_id', $this->tenant->id)
        ->where('ticket_events.created_at', '>=', $this->from)->where('ticket_events.created_at', '<', $this->to)
        ->where('ticket_events.type', 'status_changed')->whereRaw("ticket_events.new_values ->> 'status' = 'resolved'")
        ->join('report_ticket_facts as f', fn ($join) => $join->on('f.ticket_id', '=', 'ticket_events.ticket_id')->on('f.tenant_id', '=', 'ticket_events.tenant_id'))
        ->where('f.priority_level', $row['key'])->distinct()->pluck('ticket_events.ticket_id')->sort()->values()->all();
    $resolved = $records(['measure' => 'resolved']);

    expect($resolved['meta']['total'])->toBe(count($resolvedIds))
        ->and(collect($resolved['data'])->pluck('id')->sort()->values()->all())->toBe($resolvedIds)
        ->and($records([])['meta']['total'])->toBeGreaterThan($resolved['meta']['total']);
});

it('refuses a drill-down measure that is not a count of the report', function (string $measure): void {
    $this->getJson('/v1/reports/rpt-t01/records?'.http_build_query([...$this->period, 'measure' => $measure]))
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with(['number measure' => 'net_change', 'unknown measure' => 'nope']);

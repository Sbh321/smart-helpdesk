<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Automation\Actions\SuggestDuplicates;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Demo\Seeding\DemoCatalogue;
use App\Modules\Demo\Seeding\DemoPlan;
use App\Modules\Demo\Support\DemoReset;
use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The demo dataset (roadmap/11-demo-dataset.md, M3-13). The replay runs as the application role under
 * row-level security, with a frozen "now" so the SLA state is exact. The history window is kept short
 * here (10 closed tickets); the 120 live tickets, both workspaces and every scripted event are complete.
 */

const DEMO_NOW = '2026-09-21 12:00:00';

beforeEach(function (): void {
    $this->clock = new FrozenClock(DEMO_NOW);
    app()->instance(Clock::class, $this->clock);
    config(['helpdesk.webhooks.dev_allowed_hosts' => ['webhook-echo']]);
});

function seedDemo(int $history = 10): array
{
    return app(DemoReset::class)(historyTickets: $history, attachments: false);
}

/** @return list<array<string, mixed>> number, title, status, level, creation time and assignee of every ticket */
function demoTickets(): array
{
    return Tenant::findBySlug(DemoCatalogue::ACME)->run(fn (): array => DB::table('tickets')
        ->leftJoin('agent_profiles', 'agent_profiles.id', '=', 'tickets.assigned_agent_id')
        ->leftJoin('users', 'users.id', '=', 'agent_profiles.user_id')
        ->orderBy('number')
        ->get(['number', 'title', 'status', 'priority_level', 'tickets.created_at', 'users.email'])
        ->map(fn (object $row): array => (array) $row)->all());
}

it('seeds both workspaces with the planned counts, states, SLA timers, duplicates and integrations, and ticks', function (): void {
    $result = seedDemo();

    $acme = Tenant::findBySlug(DemoCatalogue::ACME);
    $globex = Tenant::findBySlug(DemoCatalogue::GLOBEX);
    expect($acme?->status->value)->toBe('active')
        ->and($globex?->status->value)->toBe('active')
        ->and($result['first_number'])->toBe(991)
        ->and($result['api_client_secret'])->toBeString();

    $acme->run(function (): void {
        $now = CarbonImmutable::parse(DEMO_NOW);
        $live = fn () => Ticket::query()->where('number', '>=', DemoCatalogue::FIRST_LIVE_NUMBER);

        expect(DB::table('users')->count())->toBe(count(DemoCatalogue::STAFF) + count(DemoCatalogue::AGENTS))
            ->and(AgentProfile::query()->count())->toBe(count(DemoCatalogue::AGENTS) + 1)
            ->and(Contact::query()->count())->toBe(33)
            ->and(Ticket::query()->count())->toBe(130)
            ->and($live()->count())->toBe(120)
            ->and($live()->max('number'))->toBe(1120);

        // Distributions: exact by construction (the dataset page allows ±2).
        $counts = fn (string $column): array => $live()->toBase()->groupBy($column)->selectRaw("{$column} AS k, count(*) AS n")->pluck('n', 'k')->map(fn ($n): int => (int) $n)->sortKeys()->all();
        $expected = DemoPlan::STATUS_TARGETS;
        ksort($expected);
        expect($counts('status'))->toBe($expected)
            ->and($counts('priority_level'))->toBe(DemoPlan::PRIORITY_TARGETS)
            ->and($counts('created_via'))->toBe(['api' => 20, 'ui' => 100]);

        // Anchors carry their literal titles and numbers.
        foreach (DemoCatalogue::ANCHORS as $number => [$title]) {
            expect(Ticket::query()->where('number', $number)->value('title'))->toBe($title);
        }

        // SLA: the breached anchors, the escalation, and near-breach timers due within 40 minutes.
        $timer = fn (int $number, string $kind): ?string => DB::table('ticket_sla_timers')
            ->join('tickets', 'tickets.id', '=', 'ticket_sla_timers.ticket_id')
            ->where('tickets.number', $number)->where('kind', $kind)->orderByDesc('cycle')->value('state');
        expect($timer(1101, 'resolution'))->toBe('breached')
            ->and($timer(1101, 'first_response'))->toBe('met')
            ->and($timer(1102, 'first_response'))->toBe('breached')
            ->and($timer(1102, 'resolution'))->toBe('running')
            ->and($timer(1103, 'resolution'))->toBe('breached')
            ->and($timer(1104, 'resolution'))->toBe('warning')
            ->and($timer(1105, 'first_response'))->toBe('running')
            ->and(Ticket::query()->where('number', 1103)->first()?->priority_override_level?->value)->toBe('P1')
            ->and(Ticket::query()->where('number', 1103)->value('priority_override_reason'))->toContain('SLA escalation')
            ->and(DB::table('ticket_sla_timers')->join('tickets', 'tickets.id', '=', 'ticket_sla_timers.ticket_id')
                ->where('number', 1110)->where('kind', 'resolution')->max('cycle'))->toBe(2);

        $near = DB::table('ticket_sla_timers')->join('tickets', 'tickets.id', '=', 'ticket_sla_timers.ticket_id')
            ->whereIn('state', ['running', 'warning'])
            ->where('due_at', '>', $now)->where('due_at', '<=', $now->addMinutes(40))
            ->pluck('number')->all();
        expect(count($near))->toBeGreaterThanOrEqual(5)
            ->and($near)->toContain(1104, 1105, 1062, 1063, 1064);

        // Duplicates: every cluster's later member has the earlier one as a stored suggestion, and the
        // DuplicateStrategy finds #1031 for the text the demo types.
        foreach ([1032 => 1031, 1041 => 1040, 1056 => 1055, 1061 => 1060, 1073 => 1072, 1081 => 1080] as $later => $earlier) {
            $suggested = DB::table('ticket_duplicate_suggestions')
                ->join('tickets as t', 't.id', '=', 'ticket_duplicate_suggestions.ticket_id')
                ->join('tickets as c', 'c.id', '=', 'ticket_duplicate_suggestions.candidate_ticket_id')
                ->where('t.number', $later)->pluck('c.number')->all();
            expect($suggested)->toContain($earlier);
        }
        $preview = app(SuggestDuplicates::class)(new TicketText((string) Str::uuid7(), DemoCatalogue::GOLDEN_TITLE, DemoCatalogue::GOLDEN_DESCRIPTION));
        $best = $preview->matches[0] ?? null;
        expect($best)->not->toBeNull()
            ->and(Ticket::query()->whereKey($best?->ticketId)->value('number'))->toBe(1031)
            ->and($best?->score())->toBeGreaterThanOrEqual(0.55);

        // Assignment: every active assigned ticket has an explained assignment; nobody above capacity.
        $active = Ticket::query()->whereIn('status', ['assigned', 'in_progress', 'pending'])->get();
        foreach ($active as $ticket) {
            $explanation = json_decode((string) DB::table('ticket_assignments')->where('ticket_id', $ticket->id)
                ->orderByDesc('created_at')->value('explanation'), true);
            expect($explanation['strategy'] ?? null)->toBe('least_loaded_agent');
        }
        foreach (AgentProfile::query()->get() as $agent) {
            expect($agent->active_ticket_count)->toBeLessThanOrEqual($agent->capacity)
                ->and($agent->active_ticket_count)->toBe(Ticket::query()->where('assigned_agent_id', $agent->id)->whereIn('status', ['assigned', 'in_progress', 'pending'])->count());
        }

        // Notifications: at least three per agent, at least one unread.
        foreach (array_keys(DemoCatalogue::AGENTS) as $email) {
            $userId = DB::table('users')->where('email', $email)->value('id');
            expect(DB::table('notifications')->where('notifiable_id', $userId)->count())->toBeGreaterThanOrEqual(3)
                ->and(DB::table('notifications')->where('notifiable_id', $userId)->whereNull('read_at')->count())->toBeGreaterThanOrEqual(1);
        }

        // Integrations: the OAuth client and the webhook subscription with one dead and one retried delivery.
        expect(ApiClient::query()->where('name', DemoCatalogue::API_CLIENT)->first()?->scopes)->toBe(DemoCatalogue::API_CLIENT_SCOPES)
            ->and(DB::table('webhook_subscriptions')->count())->toBe(1)
            ->and(DB::table('webhook_deliveries')->where('state', 'dead')->count())->toBe(1)
            ->and(DB::table('webhook_deliveries')->where('state', 'succeeded')->where('attempt', 2)->count())->toBe(1)
            ->and(DB::table('webhook_deliveries')->whereNotIn('state', ['succeeded', 'dead'])->count())->toBe(0);

        // History is genuine: entity changes carry replayed instants, and the reports were rebuilt.
        expect(CarbonImmutable::parse((string) DB::table('entity_changes')->min('occurred_at'))->lessThan($now->subDays(89)))->toBeTrue()
            ->and(DB::table('report_ticket_facts')->count())->toBe(130)
            ->and(DB::table('report_daily_snapshots')->count())->toBeGreaterThan(0)
            ->and(DB::table('audit_logs')->where('action', 'sla_policy.updated')->count())->toBe(1)
            ->and(DB::table('audit_logs')->where('action', 'settings.updated')->count())->toBe(2);
    });

    $globex->run(function (): void {
        expect(Ticket::query()->count())->toBe(8)
            ->and(DB::table('users')->count())->toBe(2)
            ->and(Contact::query()->count())->toBe(5)
            ->and(DB::table('webhook_subscriptions')->count())->toBe(1);
    });

    $this->artisan('reports:verify', ['--days' => 90, '--sample' => 200])->assertSuccessful();

    // demo:tick: the timers move 30 minutes into the past and the sweep warns and breaches.
    $other = createTenant('initech-support');
    $acme = Tenant::findBySlug(DemoCatalogue::ACME);
    $due = fn (int $number, string $kind): CarbonImmutable => $acme->run(fn (): CarbonImmutable => CarbonImmutable::parse((string) DB::table('ticket_sla_timers')
        ->join('tickets', 'tickets.id', '=', 'ticket_sla_timers.ticket_id')
        ->where('number', $number)->where('kind', $kind)->orderByDesc('cycle')->value('due_at')));
    $state = fn (int $number, string $kind): string => $acme->run(fn (): string => (string) DB::table('ticket_sla_timers')
        ->join('tickets', 'tickets.id', '=', 'ticket_sla_timers.ticket_id')
        ->where('number', $number)->where('kind', $kind)->orderByDesc('cycle')->value('state'));
    $before = $due(1104, 'resolution');

    $this->artisan('demo:tick', ['minutes' => '30m'])->assertSuccessful();

    expect($due(1104, 'resolution')->equalTo($before->subMinutes(30)))->toBeTrue()
        ->and($state(1104, 'resolution'))->toBe('breached')
        ->and($state(1105, 'first_response'))->toBe('warning')
        ->and($state(1102, 'resolution'))->toBe('running');

    $this->artisan('demo:tick', ['minutes' => 'soon'])->assertFailed();
    expect($other->run(fn (): int => DB::table('ticket_sla_timers')->count()))->toBe(0);
});

it('is deterministic and resets only the demo workspaces, leaving every other workspace untouched', function (): void {
    $other = createTenant('initech-support');
    $user = createTenantUser($other, ['email' => 'owner@initech.test']);
    $other->run(fn () => Contact::query()->create(['name' => 'Kept Contact', 'email' => 'kept@initech.test']));
    $before = $other->run(fn (): array => [DB::table('users')->pluck('id')->all(), DB::table('contacts')->pluck('id')->all()]);

    seedDemo();
    $firstAcme = Tenant::findBySlug(DemoCatalogue::ACME)?->id;
    $first = demoTickets();
    seedDemo();

    // Deterministic: the same tickets, titles, states, times and assignees in the same order.
    expect(demoTickets())->toBe($first)
        ->and(count($first))->toBe(130);

    expect(Tenant::findBySlug('initech-support')?->id)->toBe($other->id)
        ->and($other->run(fn (): array => [DB::table('users')->pluck('id')->all(), DB::table('contacts')->pluck('id')->all()]))->toBe($before)
        ->and($before[0])->toBe([$user->id])
        ->and(Tenant::findBySlug(DemoCatalogue::ACME)?->id)->not->toBe($firstAcme)
        ->and(Tenant::query()->whereKey($firstAcme)->exists())->toBeFalse()
        ->and(Tenant::query()->count())->toBe(3);
});

it('refuses in production unless the instance is a demo and --force is given', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    config(['helpdesk.demo.instance' => false]);
    expect(DemoReset::refusal(true))->toContain('production instance');
    $this->artisan('demo:reset', ['--force' => true])->assertFailed();
    $this->artisan('demo:tick', ['minutes' => '30', '--force' => true])->assertFailed();

    config(['helpdesk.demo.instance' => true]);
    expect(DemoReset::refusal(false))->toContain('--force')
        ->and(DemoReset::refusal(true))->toBeNull();
    $this->artisan('demo:reset')->assertFailed();
    expect(Tenant::findBySlug(DemoCatalogue::ACME))->toBeNull();
});

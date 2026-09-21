<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Reporting\Jobs\RefreshTicketReport;
use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Reporting\Support\TicketReportWriter;
use App\Modules\Sla\Models\BusinessCalendar;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

// report_ticket_intervals and report_ticket_facts (docs/05-algorithms/history-and-time-analytics.md §4).

function npt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Kathmandu')->utc();
}

function storedIntervals(string $ticketId): array
{
    return ReportTicketInterval::query()->withoutTenancy()->where('ticket_id', $ticketId)->orderBy('seq')->get()
        ->map(fn (ReportTicketInterval $row): array => [
            $row->status, $row->assigned_agent_id, $row->priority_level, $row->wall_seconds, $row->business_seconds,
        ])->all();
}

/**
 * The worked example: office hours Sunday–Friday 10:00–17:00 in Asia/Kathmandu; created Thursday 15:00,
 * assigned to Asha 15:30, in progress 16:00, pending Friday 16:30, in progress Sunday 11:00, reassigned
 * to Chen 12:00, resolved 14:00; now is Monday 10:00.
 *
 * @return array{Ticket, AgentProfile, AgentProfile}
 */
function workedExampleTicket(Tenant $tenant, bool $withCreatedEvent = true): array
{
    $open = ['10:00', '17:00'];
    $calendar = BusinessCalendar::factory()->forTenant($tenant)->create([
        'timezone' => 'Asia/Kathmandu',
        'weekly_hours' => ['sun' => [$open], 'mon' => [$open], 'tue' => [$open], 'wed' => [$open], 'thu' => [$open], 'fri' => [$open]],
    ]);
    $team = Team::factory()->forTenant($tenant)->create(['name' => 'Support']);
    $asha = AgentProfile::factory()->forTenant($tenant)->create();
    $chen = AgentProfile::factory()->forTenant($tenant)->create();
    $ticket = Ticket::factory()->forTenant($tenant)->create([
        'status' => TicketStatus::Resolved,
        'assigned_agent_id' => $chen->id,
        'team_id' => $team->id,
        'priority_level' => Priority::P3,
        'created_at' => npt('2026-09-17 15:00'),
        'resolved_at' => npt('2026-09-20 14:00'),
    ]);
    TicketSlaTimer::factory()->forTenant($tenant)->create([
        'ticket_id' => $ticket->id, 'kind' => 'resolution', 'state' => 'met', 'calendar_id' => $calendar->id,
    ]);

    $tenant->run(function () use ($ticket, $team, $asha, $chen, $withCreatedEvent): void {
        $event = fn (string $type, string $at, array $old, array $new) => TicketEvent::query()->create([
            'ticket_id' => $ticket->id, 'type' => $type, 'actor_type' => 'system', 'actor_id' => null,
            'old_values' => $old, 'new_values' => $new, 'note' => null, 'created_at' => npt($at),
        ]);
        if ($withCreatedEvent) {
            $event('created', '2026-09-17 15:00', [], ['status' => 'open', 'priority' => 'P3']);
        }
        $event('assigned', '2026-09-17 15:30', ['status' => 'open', 'agent_id' => null, 'team_id' => $team->id],
            ['status' => 'assigned', 'agent_id' => $asha->id, 'team_id' => $team->id]);
        $event('status_changed', '2026-09-17 16:00', ['status' => 'assigned'], ['status' => 'in_progress']);
        $event('comment_added', '2026-09-17 16:10', [], ['comment_id' => 'x']);
        $event('status_changed', '2026-09-18 16:30', ['status' => 'in_progress'], ['status' => 'pending']);
        $event('status_changed', '2026-09-20 11:00', ['status' => 'pending'], ['status' => 'in_progress']);
        $event('assigned', '2026-09-20 12:00', ['status' => 'in_progress', 'agent_id' => $asha->id, 'team_id' => $team->id],
            ['status' => 'in_progress', 'agent_id' => $chen->id, 'team_id' => $team->id]);
        $event('status_changed', '2026-09-20 14:00', ['status' => 'in_progress'], ['status' => 'resolved']);
    });

    return [$ticket, $asha, $chen];
}

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock(npt('2026-09-21 10:00')->toDateTimeString()));
    $this->tenant = createTenant('reports', ['timezone' => 'Asia/Kathmandu']);
    // The assertions read this workspace's rows, which row-level security shows only inside it.
    tenancy()->initialize($this->tenant);
});

it('reproduces the worked example intervals with wall-clock and business durations', function (): void {
    [$ticket, $asha, $chen] = workedExampleTicket($this->tenant);

    $this->tenant->run(fn () => app(TicketReportWriter::class)->refresh($ticket->id));

    $h = 3600;
    expect(storedIntervals($ticket->id))->toBe([
        ['open', null, 'P3', (int) (0.5 * $h), (int) (0.5 * $h)],
        ['assigned', $asha->id, 'P3', (int) (0.5 * $h), (int) (0.5 * $h)],
        ['in_progress', $asha->id, 'P3', (int) (24.5 * $h), (int) (7.5 * $h)],
        ['pending', $asha->id, 'P3', (int) (42.5 * $h), (int) (1.5 * $h)], // Saturday closed
        ['in_progress', $asha->id, 'P3', $h, $h],
        ['in_progress', $chen->id, 'P3', 2 * $h, 2 * $h],
        ['resolved', $chen->id, 'P3', null, null], // open: its duration runs to now
    ]);
    $open = ReportTicketInterval::query()->withoutTenancy()->where('ticket_id', $ticket->id)->where('seq', 6)->sole();
    expect($open->ends_at)->toBeNull()->and($open->starts_at->equalTo(npt('2026-09-20 14:00')))->toBeTrue();
});

it('derives the facts of the worked example', function (): void {
    [$ticket] = workedExampleTicket($this->tenant);

    $this->tenant->run(fn () => app(TicketReportWriter::class)->refresh($ticket->id));

    $fact = ReportTicketFact::query()->withoutTenancy()->where('ticket_id', $ticket->id)->sole();
    expect($fact->pending_s)->toBe(153_000) // 42.5 h
        ->and($fact->unassigned_s)->toBe(1_800)
        ->and($fact->reassign_count)->toBe(1)
        ->and($fact->resolution_wall_s)->toBe(71 * 3600)
        ->and($fact->getAttribute('resolution_business_s'))->toBe(13 * 3600) // Thu 2 h + Fri 7 h + Sun 4 h
        ->and($fact->first_response_wall_s)->toBeNull()
        ->and($fact->resolution_sla)->toBe('met')
        ->and($fact->first_response_sla)->toBeNull()
        ->and($fact->initial_priority_level)->toBe('P3')
        ->and($fact->refreshed_at->equalTo(npt('2026-09-21 10:00')))->toBeTrue();
});

it('starts from the old values of the first changes when a ticket has no created event', function (): void {
    [$ticket] = workedExampleTicket($this->tenant, withCreatedEvent: false);

    $this->tenant->run(fn () => app(TicketReportWriter::class)->refresh($ticket->id));

    expect(array_column(storedIntervals($ticket->id), 0))
        ->toBe(['open', 'assigned', 'in_progress', 'pending', 'in_progress', 'in_progress', 'resolved']);
});

it('gives the same rows however often it runs, and drops them with the ticket', function (): void {
    [$ticket] = workedExampleTicket($this->tenant);
    $writer = app(TicketReportWriter::class);

    $this->tenant->run(fn () => $writer->refresh($ticket->id));
    $first = storedIntervals($ticket->id);
    $this->tenant->run(fn () => $writer->refresh($ticket->id));

    expect(storedIntervals($ticket->id))->toBe($first)
        ->and(ReportTicketFact::query()->withoutTenancy()->where('ticket_id', $ticket->id)->count())->toBe(1);

    $this->tenant->run(fn () => $writer->refresh('01a0c000-0000-7000-8000-000000000000'));
    Ticket::query()->withoutTenancy()->whereKey($ticket->id)->delete();
    expect(ReportTicketInterval::query()->withoutTenancy()->where('ticket_id', $ticket->id)->count())->toBe(0);
});

it('queues a refresh on the reports queue after each ticket event, once per waiting ticket', function (): void {
    Queue::fake();
    actingAsRole($this->tenant, 'manager');
    $ticket = Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::Assigned]);
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/v1/tickets/{$ticket->id}/comments", ['body' => 'On it.', 'visibility' => 'internal'])->assertCreated();

    Queue::assertPushedOn('reports', RefreshTicketReport::class, fn (RefreshTicketReport $job): bool => $job->ticketId === $ticket->id);
    expect($this->tenant->run(fn () => (new RefreshTicketReport($ticket->id))->uniqueId()))->toBe($this->tenant->id.':'.$ticket->id);
});

it('rebuilds to exactly what the incremental jobs wrote, and verify finds no drift', function (): void {
    // With the sync queue every API action refreshes the ticket as it happens.
    $manager = actingAsRole($this->tenant, 'manager');
    AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $manager->id, 'capacity' => 50, 'availability' => 'available']);
    [$contact, $category] = [
        Contact::factory()->forTenant($this->tenant)->create(),
        Category::factory()->forTenant($this->tenant)->create(),
    ];
    tenancy()->initialize($this->tenant);
    $clock = app(Clock::class);
    for ($n = 0; $n < 6; $n++) {
        $id = $this->postJson('/v1/tickets', [
            'title' => "Printer {$n} jams", 'description' => 'Paper jams on every job.',
            'contact_id' => $contact->id, 'category_id' => $category->id, 'impact' => 1 + $n % 4, 'urgency' => 2,
        ])->assertCreated()->json('data.id');
        $clock->set($clock->now()->addMinutes(20)->toDateTimeString());
        $this->postJson("/v1/tickets/{$id}/transition", ['status' => 'in_progress'])->assertOk();
        if ($n % 2 === 0) {
            $clock->set($clock->now()->addHours(3)->toDateTimeString());
            $this->postJson("/v1/tickets/{$id}/transition", ['status' => 'pending'])->assertOk();
        }
        if ($n % 3 === 0) {
            $clock->set($clock->now()->addHours(5)->toDateTimeString());
            $this->postJson("/v1/tickets/{$id}/transition", ['status' => 'resolved', 'comment' => 'Cleaned the rollers.'])->assertOk();
        }
    }

    $snapshot = fn (): array => [
        ReportTicketInterval::query()->withoutTenancy()->orderBy('ticket_id')->orderBy('seq')->get()
            ->map(fn ($row) => collect($row->getAttributes())->except('id')->all())->all(),
        ReportTicketFact::query()->withoutTenancy()->orderBy('ticket_id')->get()
            ->map(fn ($row) => collect($row->getAttributes())->except(['id', 'refreshed_at'])->all())->all(),
    ];
    $incremental = $snapshot();
    expect($incremental[0])->not->toBeEmpty()->and($incremental[1])->toHaveCount(6);

    Artisan::call('reports:rebuild', ['--tenant' => 'reports']);
    expect($snapshot())->toEqual($incremental);

    expect(Artisan::call('reports:verify', ['--tenant' => 'reports', '--sample' => 10]))->toBe(0);
    ReportTicketFact::query()->withoutTenancy()->limit(1)->update(['pending_s' => 999]);
    expect(Artisan::call('reports:verify', ['--tenant' => 'reports', '--sample' => 10]))->toBe(1);
});

it('takes an event dated before the ticket (a shifted clock) to have happened at creation', function (): void {
    $ticket = Ticket::factory()->forTenant($this->tenant)->create([
        'status' => TicketStatus::InProgress, 'created_at' => npt('2026-09-20 12:00'),
    ]);
    $this->tenant->run(fn () => TicketEvent::query()->create([
        'ticket_id' => $ticket->id, 'type' => 'status_changed', 'actor_type' => 'system', 'actor_id' => null,
        'old_values' => ['status' => 'open'], 'new_values' => ['status' => 'in_progress'], 'note' => null,
        'created_at' => npt('2026-09-20 11:00'),
    ]));

    $this->tenant->run(fn () => app(TicketReportWriter::class)->refresh($ticket->id));

    expect(array_column(storedIntervals($ticket->id), 0))->toBe(['open', 'in_progress'])
        ->and(storedIntervals($ticket->id)[0][3])->toBe(0);
});

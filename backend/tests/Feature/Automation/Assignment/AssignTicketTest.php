<?php

declare(strict_types=1);

use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Automation\Actions\AssignTicket;
use App\Modules\Automation\Events\NoEligibleAgent;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Events\TicketAssigned;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Event;

// docs/05-algorithms/agent-assignment.md and docs/04-domain/agents-and-teams.md, end to end.

beforeEach(function (): void {
    $this->tenant = createTenant('assignment', ['timezone' => 'UTC']);
    // A Monday, 10:00 UTC.
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 10:00:00'));
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<Skill>  $skills
 * @param  list<Team>  $teams
 */
function asgAgent(string $name, array $attributes = [], array $skills = [], array $teams = []): AgentProfile
{
    $user = createTenantUser(tenant(), ['name' => $name]);
    $agent = AgentProfile::factory()->create(['user_id' => $user->id, ...$attributes]);
    foreach ($skills as $skill) {
        $agent->skills()->attach($skill->id, ['tenant_id' => $agent->tenant_id, 'level' => 3]);
    }
    foreach ($teams as $team) {
        $agent->teams()->attach($team->id, ['tenant_id' => $agent->tenant_id, 'joined_at' => '2026-09-01 00:00:00+00']);
    }

    return $agent;
}

/** @param array<string, mixed> $attributes */
function asgTicket(?Category $category = null, array $attributes = []): Ticket
{
    return Ticket::factory()->create([
        'category_id' => ($category ?? Category::factory()->create())->id,
        ...$attributes,
    ]);
}

function asgLatest(Ticket $ticket): TicketAssignment
{
    return TicketAssignment::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail();
}

it('reproduces the worked example: Chen wins the tie on the older last assignment', function (): void {
    $this->tenant->run(function (): void {
        $billing = Skill::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
        $category = Category::factory()->create(['name' => 'Billing']);
        $category->skills()->attach($billing->id, ['tenant_id' => $this->tenant->id]);

        $asha = asgAgent('Asha', ['active_ticket_count' => 3, 'capacity' => 10, 'last_assigned_at' => '2026-09-21 09:40:00'], [$billing]);
        $bikram = asgAgent('Bikram', ['active_ticket_count' => 2, 'capacity' => 5, 'last_assigned_at' => '2026-09-21 08:15:00'], [$billing]);
        $chen = asgAgent('Chen', ['active_ticket_count' => 3, 'capacity' => 10, 'last_assigned_at' => '2026-09-21 09:10:00'], [$billing]);
        $dev = asgAgent('Dev', ['active_ticket_count' => 0, 'capacity' => 8]);
        $esha = asgAgent('Esha', ['active_ticket_count' => 1, 'capacity' => 10, 'availability' => AgentAvailability::Away], [$billing]);

        Event::fake([TicketAssigned::class, NoEligibleAgent::class]);
        $ticket = app(AssignTicket::class)(asgTicket($category));

        expect($ticket->assigned_agent_id)->toBe($chen->id)
            ->and($ticket->status)->toBe(TicketStatus::Assigned)
            ->and($chen->refresh()->active_ticket_count)->toBe(4)
            ->and($chen->last_assigned_at?->toDateTimeString())->toBe('2026-09-21 10:00:00')
            ->and($asha->refresh()->active_ticket_count)->toBe(3);
        Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $event): bool => $event->agentId === $chen->id);
        Event::assertNotDispatched(NoEligibleAgent::class);

        $row = asgLatest($ticket);
        $excluded = collect($row->explanation['excluded'])->keyBy('agent_id');
        expect($row->reason)->toBe('auto')
            ->and($row->getAttribute('agent_profile_id'))->toBe($chen->id)
            ->and($row->getAttribute('assigned_by_user_id'))->toBeNull()
            ->and($row->explanation['strategy'])->toBe('least_loaded_agent')
            ->and($row->explanation['strategy_version'])->toBe('1.0.0')
            ->and($row->explanation['outcome'])->toBe('assigned')
            ->and($row->explanation['selection'])->toBe('automatic')
            ->and(array_column($row->explanation['ranking'], 'agent_id'))->toBe([$chen->id, $asha->id, $bikram->id])
            ->and(array_column($row->explanation['ranking'], 'load'))->toEqual([0.3, 0.3, 0.4])
            ->and($excluded[$dev->id])->toEqual(['agent_id' => $dev->id, 'reason' => 'missing_skill', 'missing_skills' => ['billing']])
            ->and($excluded[$esha->id]['reason'])->toBe('not_available');

        $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'assigned')->sole();
        expect($event->getAttribute('actor_type'))->toBe('system')
            ->and($event->getAttribute('new_values'))->toMatchArray(['status' => 'assigned', 'agent_id' => $chen->id, 'outcome' => 'assigned']);
    });
});

it('stores every exclusion reason and keeps the routed team when nobody is eligible', function (): void {
    config(['helpdesk.shifts.enforce' => true]);

    $this->tenant->run(function (): void {
        $network = Skill::factory()->create(['slug' => 'network']);
        $team = Team::factory()->create();
        $category = Category::factory()->create(['default_team_id' => $team->id]);
        $category->skills()->attach($network->id, ['tenant_id' => $this->tenant->id]);

        $inactive = asgAgent('Inactive', [], [$network], [$team]);
        $inactive->user->forceFill(['is_active' => false])->save();
        $offline = asgAgent('Offline', ['availability' => AgentAvailability::Offline], [$network], [$team]);
        $offShift = asgAgent('Off shift', [], [$network], [$team]);
        $unskilled = asgAgent('Unskilled', [], [], [$team]);
        $outsider = asgAgent('Outsider', [], [$network]);
        $full = asgAgent('Full', ['active_ticket_count' => 2, 'capacity' => 2], [$network], [$team]);
        foreach ([$inactive, $offline, $unskilled, $outsider, $full] as $agent) {
            AgentShift::factory()->create([
                'agent_profile_id' => $agent->id, 'weekday' => 1, 'starts_at' => '00:00:00', 'ends_at' => '23:59:00',
            ]);
        }

        Event::fake([TicketAssigned::class, NoEligibleAgent::class]);
        $ticket = app(AssignTicket::class)(asgTicket($category));

        expect($ticket->assigned_agent_id)->toBeNull()
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->refresh()->team_id)->toBe($team->id);

        $row = asgLatest($ticket);
        expect($row->reason)->toBe('auto')
            ->and($row->getAttribute('agent_profile_id'))->toBeNull()
            ->and($row->getAttribute('team_id'))->toBe($team->id)
            ->and($row->explanation['outcome'])->toBe('no_eligible_agent')
            ->and($row->explanation['ranking'])->toBe([])
            ->and(collect($row->explanation['excluded'])->pluck('reason', 'agent_id')->all())->toEqual([
                $inactive->id => 'inactive',
                $offline->id => 'not_available',
                $offShift->id => 'off_shift',
                $unskilled->id => 'missing_skill',
                $outsider->id => 'not_in_team',
                $full->id => 'at_capacity',
            ]);

        expect(TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'assigned')->sole()->getAttribute('new_values'))
            ->toMatchArray(['agent_id' => null, 'team_id' => $team->id, 'outcome' => 'no_eligible_agent']);
        Event::assertDispatched(NoEligibleAgent::class, fn (NoEligibleAgent $event): bool => $event->ticketId === $ticket->id
            && $event->teamId === $team->id && count($event->exclusions) === 6);
        Event::assertNotDispatched(TicketAssigned::class);
    });
});

it('never gives the last slot of an agent to two tickets', function (): void {
    $this->tenant->run(function (): void {
        $agent = asgAgent('Only one', ['capacity' => 1]);
        $assign = app(AssignTicket::class);

        $first = $assign(asgTicket());
        // What a concurrent request sees once the first transaction commits and releases the
        // agent row lock: the counter it reads under its own lock is already 1.
        $second = $assign(asgTicket());

        expect($first->assigned_agent_id)->toBe($agent->id)
            ->and($second->assigned_agent_id)->toBeNull()
            ->and($second->status)->toBe(TicketStatus::Open)
            ->and(asgLatest($second)->explanation['excluded'])->toEqual([['agent_id' => $agent->id, 'reason' => 'at_capacity']])
            ->and($agent->refresh()->active_ticket_count)->toBe(1);
    });
});

it('reads agent rows FOR UPDATE before it reads their workload', function (): void {
    $this->tenant->run(function (): void {
        asgAgent('Locked');
        $ticket = asgTicket();
        // Only the action's own queries: after-commit listeners (notifications) read agents without a lock.
        Event::fake([TicketAssigned::class]);

        DB::enableQueryLog();
        app(AssignTicket::class)($ticket);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $ticketLock = array_key_first(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "tickets"') && str_contains($sql, 'for update')));
        $agentLock = array_key_first(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "agent_profiles"') && str_contains($sql, 'for update')));
        $firstWrite = array_key_first(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update') || str_starts_with($sql, 'insert')));

        expect($ticketLock)->not->toBeNull()
            ->and($agentLock)->toBeGreaterThan($ticketLock)
            ->and($queries[$agentLock])->toContain('order by "id" asc')
            ->and($firstWrite)->toBeGreaterThan($agentLock)
            // Every read of agent_profiles in the action holds the lock.
            ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "agent_profiles"') && ! str_contains($sql, 'for update')))->toBe([]);
    });
});

it('routes to the category default team and picks inside it', function (): void {
    $this->tenant->run(function (): void {
        $team = Team::factory()->create();
        $category = Category::factory()->create(['default_team_id' => $team->id]);
        $idleOutsider = asgAgent('Idle outsider', ['active_ticket_count' => 0]);
        $busyMember = asgAgent('Busy member', ['active_ticket_count' => 6], [], [$team]);

        $ticket = app(AssignTicket::class)(asgTicket($category));

        expect($ticket->team_id)->toBe($team->id)
            ->and($ticket->assigned_agent_id)->toBe($busyMember->id)
            ->and(asgLatest($ticket)->explanation['excluded'])->toEqual([['agent_id' => $idleOutsider->id, 'reason' => 'not_in_team']]);
    });
});

it('keeps the counters right through assign, resolve, reopen and unassign, and reconciles drift', function (): void {
    $manager = actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function () use ($manager): void {
        $agent = asgAgent('Counter');
        $ticket = asgTicket();

        $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertOk();
        expect($agent->refresh()->active_ticket_count)->toBe(1);

        $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])->assertOk();
        expect($agent->refresh()->active_ticket_count)->toBe(1);

        $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'resolved', 'comment' => 'Fixed.'])->assertOk();
        expect($agent->refresh()->active_ticket_count)->toBe(0);

        $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])->assertOk();
        expect($agent->refresh()->active_ticket_count)->toBe(1);

        $this->postJson("/v1/tickets/{$ticket->id}/unassign")->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_agent_id', null);
        expect($agent->refresh()->active_ticket_count)->toBe(0);

        $row = asgLatest($ticket);
        expect($row->reason)->toBe('unassign')
            ->and($row->getAttribute('agent_profile_id'))->toBeNull()
            ->and($row->getAttribute('previous_agent_profile_id'))->toBe($agent->id)
            ->and($row->getAttribute('assigned_by_user_id'))->toBe($manager->id)
            ->and(TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'unassigned')->count())->toBe(1);

        // Drift in both directions is repaired from the live ticket count. The HTTP calls above ended
        // tenancy when they terminated, so it is started again for the factories below.
        tenancy()->initialize($this->tenant);
        $other = asgAgent('Drifted');
        asgTicket(null, ['status' => TicketStatus::Pending, 'assigned_agent_id' => $other->id]);
        asgTicket(null, ['status' => TicketStatus::Resolved, 'assigned_agent_id' => $other->id, 'resolved_at' => '2026-09-20 10:00:00']);
        $agent->forceFill(['active_ticket_count' => 7])->save();
    });

    $this->artisan('agents:reconcile-workload')->assertSuccessful();

    $this->tenant->run(function (): void {
        expect(AgentProfile::query()->orderBy('active_ticket_count')->pluck('active_ticket_count')->all())->toBe([0, 1]);
    });
});

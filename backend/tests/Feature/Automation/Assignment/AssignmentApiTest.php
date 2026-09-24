<?php

declare(strict_types=1);

use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

// The HTTP surface of docs/05-algorithms/agent-assignment.md: stable codes, manual override, guards.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 10:00:00'));
    $this->tenant = createTenant('assign-api', ['timezone' => 'UTC']);
});

/** @param array<string, mixed> $attributes */
function apiAgent(array $attributes = []): AgentProfile
{
    return AgentProfile::factory()->forTenant(test()->tenant)->create([
        'user_id' => createTenantUser(test()->tenant)->id,
        'capacity' => 5,
        'active_ticket_count' => 0,
        'availability' => AgentAvailability::Available,
        ...$attributes,
    ]);
}

/** @param array<string, mixed> $attributes */
function apiTicket(array $attributes = []): Ticket
{
    return Ticket::factory()->forTenant(test()->tenant)->create($attributes);
}

function apiAssignments(Ticket $ticket): int
{
    return TicketAssignment::query()->withoutTenancy()->where('ticket_id', $ticket->id)->count();
}

it('answers 422 no_eligible_agent with the exclusions and still stores the attempt', function (): void {
    actingAsRole($this->tenant, 'manager');
    $away = apiAgent(['availability' => AgentAvailability::Away]);
    $full = apiAgent(['capacity' => 1, 'active_ticket_count' => 1]);
    $ticket = apiTicket();

    $response = $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'no_eligible_agent')
        ->assertJsonPath('meta.ticket_id', $ticket->id)
        ->assertJsonPath('meta.team_id', null);

    expect(collect($response->json('meta.exclusions'))->pluck('reason', 'agent_id')->all())
        ->toEqual([$away->id => 'not_available', $full->id => 'at_capacity']);

    $stored = Ticket::query()->withoutTenancy()->findOrFail($ticket->id);
    $row = TicketAssignment::query()->withoutTenancy()->where('ticket_id', $ticket->id)->sole();
    expect($stored->assigned_agent_id)->toBeNull()
        ->and($stored->status)->toBe(TicketStatus::Open)
        ->and($row->reason)->toBe('auto')
        ->and($row->explanation['outcome'])->toBe('no_eligible_agent');
});

it('answers 409 already_assigned for a second auto-assign and for the same manual choice', function (): void {
    actingAsRole($this->tenant, 'manager');
    $agent = apiAgent();
    $ticket = apiTicket();

    $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertOk()
        ->assertJsonPath('data.assigned_agent_id', $agent->id)
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assignment.reason', 'auto')
        ->assertJsonPath('data.assignment.explanation.strategy', 'least_loaded_agent');

    $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertConflict()
        ->assertJsonPath('code', 'already_assigned')
        ->assertJsonPath('meta.agent_id', $agent->id);
    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $agent->id])->assertConflict()
        ->assertJsonPath('code', 'already_assigned');

    expect(apiAssignments($ticket))->toBe(1)
        ->and(AgentProfile::query()->withoutTenancy()->findOrFail($agent->id)->active_ticket_count)->toBe(1);
});

it('lets a manager pick an agent the strategy excludes, then reassign with the previous agent recorded', function (): void {
    $manager = actingAsRole($this->tenant, 'manager');
    $full = apiAgent(['capacity' => 1, 'active_ticket_count' => 1]);
    $idle = apiAgent();
    $ticket = apiTicket();

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $full->id])->assertOk()
        ->assertJsonPath('data.assigned_agent_id', $full->id)
        ->assertJsonPath('data.status', 'assigned')
        ->assertJsonPath('data.assignment.reason', 'manual')
        ->assertJsonPath('data.assignment.previous_agent_id', null)
        ->assertJsonPath('data.assignment.assigned_by_user_id', $manager->id)
        ->assertJsonPath('data.assignment.explanation.selection', 'manual')
        ->assertJsonPath('data.assignment.explanation.manual_override', true)
        ->assertJsonPath('data.assignment.explanation.override_reason', 'at_capacity')
        ->assertJsonPath('data.assignment.explanation.recommended_agent_id', $idle->id);

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $idle->id])->assertOk()
        ->assertJsonPath('data.assigned_agent_id', $idle->id)
        ->assertJsonPath('data.assignment.reason', 'reassign')
        ->assertJsonPath('data.assignment.previous_agent_id', $full->id)
        ->assertJsonPath('data.assignment.explanation.manual_override', false);

    $counts = AgentProfile::query()->withoutTenancy()->whereIn('id', [$full->id, $idle->id])->pluck('active_ticket_count', 'id');
    expect($counts[$full->id])->toBe(1) // 1 + 1 for the assignment − 1 for the reassignment
        ->and($counts[$idle->id])->toBe(1);
});

it('routes to a team without touching the agent', function (): void {
    actingAsRole($this->tenant, 'manager');
    $team = Team::factory()->forTenant($this->tenant)->create();
    $ticket = apiTicket();

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['team_id' => $team->id])->assertOk()
        ->assertJsonPath('data.team_id', $team->id)
        ->assertJsonPath('data.assigned_agent_id', null)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.assignment.reason', 'manual')
        ->assertJsonPath('data.assignment.explanation.outcome', 'team_routed');
});

it('validates the assign body and never accepts identifiers of another workspace', function (): void {
    actingAsRole($this->tenant, 'manager');
    $other = createTenant('assign-api-b');
    $foreignAgent = AgentProfile::factory()->forTenant($other)->create();
    $foreignTeam = Team::factory()->forTenant($other)->create();
    $ticket = apiTicket();

    $this->postJson("/v1/tickets/{$ticket->id}/assign", [])->assertUnprocessable()->assertJsonValidationErrors(['agent_id', 'team_id']);
    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $foreignAgent->id])->assertUnprocessable()->assertJsonValidationErrors('agent_id');
    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['team_id' => $foreignTeam->id])->assertUnprocessable()->assertJsonValidationErrors('team_id');

    expect(apiAssignments($ticket))->toBe(0);
});

it('refuses a resolved ticket with 422 invalid_transition', function (): void {
    actingAsRole($this->tenant, 'manager');
    $agent = apiAgent();
    $ticket = apiTicket(['status' => TicketStatus::Resolved, 'resolved_at' => '2026-09-21 09:00:00']);

    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $agent->id])->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
    $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
});

it('answers 404 for a ticket of another workspace and 403 without tickets.assign', function (): void {
    $agent = apiAgent();
    $ticket = apiTicket();
    $foreign = Ticket::factory()->forTenant(createTenant('assign-api-c'))->create();

    actingAsRole($this->tenant, 'manager');
    $this->getJson("/v1/tickets/{$foreign->id}/assignment-candidates")->assertNotFound();
    $this->postJson("/v1/tickets/{$foreign->id}/assign", ['agent_id' => $agent->id])->assertNotFound();
    $this->postJson("/v1/tickets/{$foreign->id}/auto-assign")->assertNotFound();
    $this->postJson("/v1/tickets/{$foreign->id}/unassign")->assertNotFound();

    actingAsRole($this->tenant, 'agent');
    $this->getJson("/v1/tickets/{$ticket->id}/assignment-candidates")->assertForbidden();
    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $agent->id])->assertForbidden();
    $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertForbidden();
    $this->postJson("/v1/tickets/{$ticket->id}/unassign")->assertForbidden();

    expect(apiAssignments($ticket))->toBe(0)->and(apiAssignments($foreign))->toBe(0);
});

it('previews the ranking without storing anything', function (): void {
    actingAsRole($this->tenant, 'manager');
    $busy = apiAgent(['active_ticket_count' => 3]);
    $idle = apiAgent();
    $ticket = apiTicket();

    $this->getJson("/v1/tickets/{$ticket->id}/assignment-candidates")->assertOk()
        ->assertJsonPath('data.strategy', 'least_loaded_agent')
        ->assertJsonPath('data.strategy_version', '1.0.0')
        ->assertJsonPath('data.outcome', 'assigned')
        ->assertJsonPath('data.agent_id', $idle->id)
        ->assertJsonCount(2, 'data.ranking')
        ->assertJsonPath('data.excluded', []);

    expect(apiAssignments($ticket))->toBe(0)
        ->and(AgentProfile::query()->withoutTenancy()->findOrFail($busy->id)->active_ticket_count)->toBe(3);
});

it('reads back the stored explanation of the latest assignment (M4-06)', function (): void {
    actingAsRole($this->tenant, 'manager');
    $busy = apiAgent(['active_ticket_count' => 3]);
    $idle = apiAgent();
    $ticket = apiTicket();

    // Never assigned: nothing to explain.
    $this->getJson("/v1/tickets/{$ticket->id}/assignment")->assertNotFound();

    $this->postJson("/v1/tickets/{$ticket->id}/auto-assign")->assertOk();
    $stored = TicketAssignment::query()->where('ticket_id', $ticket->id)->sole();

    // The stored row, as recorded: not a recomputation, so it stays what it was when the agent's load changes.
    AgentProfile::query()->whereKey($idle->id)->update(['active_ticket_count' => 4]);

    $this->getJson("/v1/tickets/{$ticket->id}/assignment")->assertOk()
        ->assertJsonPath('data.id', $stored->id)
        ->assertJsonPath('data.reason', 'auto')
        ->assertJsonPath('data.agent_id', $idle->id)
        ->assertJsonPath('data.explanation.strategy', 'least_loaded_agent')
        ->assertJsonPath('data.explanation.agent_id', $idle->id)
        ->assertJsonPath('data.explanation.ranking.0.agent_id', $idle->id)
        ->assertJsonPath('data.explanation.ranking.0.open_tickets', 0)
        ->assertJsonPath('data.explanation.ranking.1.agent_id', $busy->id);

    // A reassignment makes the newer row the answer.
    $this->postJson("/v1/tickets/{$ticket->id}/assign", ['agent_id' => $busy->id])->assertOk();
    $this->getJson("/v1/tickets/{$ticket->id}/assignment")->assertOk()
        ->assertJsonPath('data.reason', 'reassign')
        ->assertJsonPath('data.agent_id', $busy->id)
        ->assertJsonPath('data.previous_agent_id', $idle->id);
});

it('guards the explanation like the candidates: 404 across workspaces, 403 without tickets.assign', function (): void {
    $ticket = apiTicket();
    $foreign = Ticket::factory()->forTenant(createTenant('assign-api-d'))->create();

    actingAsRole($this->tenant, 'manager');
    $this->getJson("/v1/tickets/{$foreign->id}/assignment")->assertNotFound();

    actingAsRole($this->tenant, 'agent');
    $this->getJson("/v1/tickets/{$ticket->id}/assignment")->assertForbidden();
});

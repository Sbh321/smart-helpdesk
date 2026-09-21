<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketDuplicateSuggestion;

// The M2-11 additions to GET /v1/tickets: `assignee_id=me`, `sla_state`, `has_duplicate_suggestion`,
// sort by `sla_due_at` (docs/07-api/pagination-filtering.md).

beforeEach(function (): void {
    $this->tenant = createTenant('views');
    $this->me = actingAsRole($this->tenant, 'agent');
    $this->myProfile = AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $this->me->id]);
    $this->number = fn (string $query): array => array_column($this->getJson('/v1/tickets'.$query)->assertOk()->json('data'), 'number');
});

function timerFor(Ticket $ticket, string $state, string $due, int $cycle = 1): void
{
    TicketSlaTimer::factory()->forTenant(Tenant::query()->findOrFail($ticket->tenant_id))->create([
        'ticket_id' => $ticket->id, 'kind' => 'resolution', 'state' => $state, 'due_at' => $due, 'cycle' => $cycle,
    ]);
}

it('filters "my tickets" by the signed-in agent, and matches nothing for a user without a profile', function (): void {
    $mine = Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::Assigned, 'assigned_agent_id' => $this->myProfile->id]);
    Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::Assigned, 'assigned_agent_id' => AgentProfile::factory()->forTenant($this->tenant)->create()->id]);
    $unassigned = Ticket::factory()->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);

    expect(($this->number)('?filter[assignee_id]=me'))->toBe([$mine->number])
        ->and(($this->number)('?filter[assignee_id]=me,unassigned&sort=number'))->toBe([$mine->number, $unassigned->number]);

    actingAsRole($this->tenant, 'manager', createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    expect(($this->number)('?filter[assignee_id]=me'))->toBe([]);
});

it('filters by the state of the latest resolution timer and sorts by its due time', function (): void {
    [$warning, $breached, $running, $met, $none] = Ticket::factory()->count(5)->forTenant($this->tenant)->create()->all();
    timerFor($warning, 'warning', '2026-09-21 12:00:00');
    timerFor($breached, 'breached', '2026-09-21 08:00:00');
    timerFor($running, 'met', '2026-09-20 08:00:00');                // an earlier cycle …
    timerFor($running, 'running', '2026-09-22 08:00:00', cycle: 2);  // … the latest counts
    timerFor($met, 'met', '2026-09-19 08:00:00');
    tenancy()->initialize($this->tenant);

    expect(($this->number)('?filter[sla_state]=warning,breached&sort=sla_due_at'))->toBe([$breached->number, $warning->number])
        ->and(($this->number)('?filter[sla_state]=running'))->toBe([$running->number])
        ->and(($this->number)('?filter[sla_state]=met'))->toBe([$met->number])
        // Met and timer-less tickets have no due time and go last.
        ->and(array_slice(($this->number)('?sort=sla_due_at'), 0, 3))->toBe([$breached->number, $warning->number, $running->number]);

    $this->getJson('/v1/tickets?filter[sla_state]=late')->assertStatus(422);
});

it('filters tickets with a pending duplicate suggestion', function (): void {
    $suggested = Ticket::factory()->forTenant($this->tenant)->create();
    $decided = Ticket::factory()->forTenant($this->tenant)->create();
    TicketDuplicateSuggestion::factory()->forTenant($this->tenant)->create(['ticket_id' => $suggested->id]);
    TicketDuplicateSuggestion::factory()->forTenant($this->tenant)->create(['ticket_id' => $decided->id, 'decision' => 'dismissed']);
    tenancy()->initialize($this->tenant);

    expect(($this->number)('?filter[has_duplicate_suggestion]=true'))->toBe([$suggested->number]);
});

it('returns the latest resolution timer\'s state and due time with each listed ticket', function (): void {
    [$warning, $none] = Ticket::factory()->count(2)->forTenant($this->tenant)->create()->all();
    timerFor($warning, 'warning', '2026-09-21 12:00:00');
    tenancy()->initialize($this->tenant);

    $rows = collect($this->getJson('/v1/tickets?sort=number')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$warning->id]['sla_state'])->toBe('warning')
        ->and($rows[$warning->id]['sla_due_at'])->toBe('2026-09-21T12:00:00Z')
        ->and($rows[$none->id]['sla_state'])->toBeNull()
        ->and($rows[$none->id]['sla_due_at'])->toBeNull();
    // The detail endpoint has its own SLA panel and does not carry the list fields.
    $this->getJson("/v1/tickets/{$warning->id}")->assertOk()->assertJsonMissingPath('data.sla_state');
    // Upper-case ids are ids too.
    $this->getJson('/v1/tickets?filter[assignee_id]='.strtoupper($this->myProfile->id))->assertOk();
});

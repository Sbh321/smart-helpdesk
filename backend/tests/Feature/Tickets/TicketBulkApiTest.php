<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Support\Str;

// POST /v1/tickets/bulk/transition and /bulk/assign (docs/07-api/conventions.md, roadmap M2-11).

beforeEach(function (): void {
    $this->tenant = createTenant('bulk');
    $this->manager = actingAsRole($this->tenant, 'manager');
});

it('moves 50 tickets and reports each one, including the ones that cannot move', function (): void {
    $movable = Ticket::factory()->count(45)->forTenant($this->tenant)->create(['status' => TicketStatus::Assigned]);
    $closed = Ticket::factory()->count(4)->forTenant($this->tenant)->create([
        'status' => TicketStatus::Closed, 'resolved_at' => now()->subDays(30), 'closed_at' => now()->subDays(29),
    ]);
    $foreign = Ticket::factory()->forTenant(createTenant('elsewhere'))->create();
    tenancy()->initialize($this->tenant);
    $ids = [...$movable->modelKeys(), ...$closed->modelKeys(), $foreign->id];

    $response = $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => $ids, 'status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('meta', ['total' => 50, 'succeeded' => 45, 'failed' => 5])
        ->assertJsonCount(50, 'data');

    $rows = collect($response->json('data'))->keyBy('ticket_id');
    expect($rows[$movable[0]->id])->toMatchArray(['ok' => true, 'code' => null])
        ->and($rows[$movable[0]->id]['details']['status'])->toBe('in_progress')
        ->and($rows[$closed[0]->id])->toMatchArray(['ok' => false, 'code' => 'invalid_transition'])
        ->and($rows[$closed[0]->id]['details']['number'])->toBe($closed[0]->number)
        ->and($rows[$foreign->id]['details'])->not->toHaveKey('number')
        ->and($rows[$foreign->id])->toMatchArray(['ok' => false, 'code' => 'not_found'])
        ->and(Ticket::query()->whereIn('id', $movable->modelKeys())->where('status', 'in_progress')->count())->toBe(45)
        ->and(TicketEvent::query()->where('type', 'status_changed')->count())->toBe(45);
});

it('asks each row for its own rules: a resolution needs a comment, and permissions per target', function (): void {
    $ticket = Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::InProgress]);
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$ticket->id], 'status' => 'resolved'])
        ->assertOk()->assertJsonPath('data.0.code', 'resolution_comment_required');
    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$ticket->id], 'status' => 'resolved', 'comment' => 'Fixed in bulk.'])
        ->assertOk()->assertJsonPath('data.0.ok', true);

    actingAsRole($this->tenant, 'developer', createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$ticket->id], 'status' => 'closed'])->assertForbidden();
});

it('assigns many tickets to one agent, or runs the assigner on each', function (): void {
    $agent = AgentProfile::factory()->forTenant($this->tenant)->create(['capacity' => 2, 'availability' => 'available']);
    $tickets = Ticket::factory()->count(3)->forTenant($this->tenant)->create();
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/tickets/bulk/assign', ['ticket_ids' => $tickets->modelKeys(), 'agent_id' => $agent->id])
        ->assertOk()->assertJsonPath('meta.succeeded', 3)
        ->assertJsonPath('data.0.details.agent_id', $agent->id);

    // The assigner respects capacity: two slots, three tickets.
    $fresh = Ticket::factory()->count(3)->forTenant($this->tenant)->create();
    $other = AgentProfile::factory()->forTenant($this->tenant)->create(['capacity' => 2, 'availability' => 'available']);
    tenancy()->initialize($this->tenant);
    $response = $this->postJson('/v1/tickets/bulk/assign', ['ticket_ids' => $fresh->modelKeys(), 'auto' => true])->assertOk();
    expect($response->json('meta'))->toBe(['total' => 3, 'succeeded' => 2, 'failed' => 1])
        ->and(collect($response->json('data'))->firstWhere('ok', false)['code'])->toBe('no_eligible_agent');
    expect(Ticket::query()->where('assigned_agent_id', $other->id)->count())->toBe(2);
});

it('validates the request', function (string $url, array $payload, string $field): void {
    tenancy()->initialize($this->tenant);

    $this->postJson($url, $payload)->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'no ids' => ['/v1/tickets/bulk/transition', ['ticket_ids' => [], 'status' => 'closed'], 'ticket_ids'],
    'over 100' => ['/v1/tickets/bulk/transition', ['ticket_ids' => array_map(fn () => (string) Str::uuid7(), range(1, 101)), 'status' => 'closed'], 'ticket_ids'],
    'duplicates' => ['/v1/tickets/bulk/transition', ['ticket_ids' => ['01a0c000-0000-7000-8000-000000000001', '01a0c000-0000-7000-8000-000000000001'], 'status' => 'closed'], 'ticket_ids.0'],
    'bad status' => ['/v1/tickets/bulk/transition', ['ticket_ids' => ['01a0c000-0000-7000-8000-000000000001'], 'status' => 'done'], 'status'],
    'no target' => ['/v1/tickets/bulk/assign', ['ticket_ids' => ['01a0c000-0000-7000-8000-000000000001']], 'agent_id'],
    'auto and agent' => ['/v1/tickets/bulk/assign', ['ticket_ids' => ['01a0c000-0000-7000-8000-000000000001'], 'auto' => true, 'agent_id' => '01a0c000-0000-7000-8000-000000000002'], 'auto'],
]);

it('requires tickets.assign to assign and tickets.update to transition', function (): void {
    actingAsRole($this->tenant, 'agent', createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    $id = Ticket::factory()->forTenant($this->tenant)->create()->id;
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/tickets/bulk/assign', ['ticket_ids' => [$id], 'auto' => true])->assertForbidden();
    actingAsRole($this->tenant, 'developer', createTenantUser($this->tenant));
    tenancy()->initialize($this->tenant);
    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$id], 'status' => 'in_progress'])->assertForbidden();
});

it('checks the permission of each target: tickets.update alone cannot resolve or close', function (): void {
    $ticket = Ticket::factory()->forTenant($this->tenant)->create(['status' => TicketStatus::InProgress]);
    $editor = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($editor): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $editor->givePermissionTo(['tickets.view', 'tickets.update']);
    });
    actingAsTenantUser($this->tenant, $editor);
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$ticket->id], 'status' => 'resolved', 'comment' => 'Done.'])
        ->assertOk()->assertJsonPath('data.0.code', 'forbidden')->assertJsonPath('data.0.details.number', $ticket->number);
    $this->postJson('/v1/tickets/bulk/transition', ['ticket_ids' => [$ticket->id], 'status' => 'pending'])
        ->assertOk()->assertJsonPath('data.0.ok', true);
});

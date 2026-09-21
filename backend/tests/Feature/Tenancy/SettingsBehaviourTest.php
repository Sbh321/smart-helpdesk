<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

// The workspace settings change what the product does, not only what the settings API answers (M2-01).

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = createTenant('behaviour', ['timezone' => 'UTC']);
    $this->configure = fn (Tenant $tenant, string $section, array $values) => $tenant->run(
        fn () => app(Settings::class)->update($section, $values),
    );
    $this->payload = fn (Tenant $tenant, int $impact = 4, int $urgency = 1): array => [
        'title' => 'Payroll export is empty',
        'description' => 'The monthly payroll export downloads an empty file.',
        'contact_id' => Contact::factory()->forTenant($tenant)->create()->id,
        'category_id' => Category::factory()->forTenant($tenant)->create()->id,
        'impact' => $impact,
        'urgency' => $urgency,
    ];
});

it('scores with the workspace weights and stores the settings version with the result', function (): void {
    actingAsRole($this->tenant, 'agent');
    $payload = ($this->payload)($this->tenant);
    tenancy()->initialize($this->tenant);

    // Defaults: impact 4 weighs 0.40 of 100.
    $this->postJson('/v1/tickets', $payload)->assertCreated()
        ->assertJsonPath('data.priority_score', 40)->assertJsonPath('data.priority_level', 'P3');

    ($this->configure)($this->tenant, 'automation.priority', ['baseline' => ['weights' => ['impact' => 0.75, 'urgency' => 0.0]]]);
    tenancy()->initialize($this->tenant);

    $id = $this->postJson('/v1/tickets', $payload)->assertCreated()
        ->assertJsonPath('data.priority_score', 75)->assertJsonPath('data.priority_level', 'P1')
        ->json('data.id');

    expect(Ticket::query()->withoutTenancy()->findOrFail($id)->getAttribute('priority_settings_version'))->toBe(1);
});

it('ages every workspace with its own weights in one command run', function (): void {
    $other = createTenant('behaviour-two', ['timezone' => 'UTC']);
    ($this->configure)($other, 'automation.priority', ['baseline' => ['weights' => ['impact' => 0.2, 'urgency' => 0.2, 'tier' => 0.1, 'age' => 0.5]]]);
    $make = fn (Tenant $tenant): Ticket => Ticket::factory()->forTenant($tenant)->create([
        'impact' => 1, 'urgency' => 1, 'status' => TicketStatus::Open, 'priority_score' => 0,
        'priority_level' => Priority::P4, 'created_at' => '2026-09-21 09:00:00',
    ]);
    [$mine, $theirs] = [$make($this->tenant), $make($other)];

    $this->clock->set('2026-09-24 09:00:00'); // 72 h: the age part is at its full weight
    $this->artisan('tickets:reevaluate-priority')->assertSuccessful();

    $score = fn (Ticket $ticket): float => (float) findInAnyTenant(Ticket::class, $ticket->id)->priority_score;
    expect($score($mine))->toBe(10.0)->and($score($theirs))->toBe(50.0);
});

it('leaves a new ticket unassigned when automatic assignment is switched off', function (): void {
    $agent = actingAsRole($this->tenant, 'agent');
    AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $agent->id, 'capacity' => 5, 'availability' => 'available']);
    $payload = ($this->payload)($this->tenant);
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/tickets', $payload)->assertCreated()->assertJsonPath('data.status', 'assigned');

    ($this->configure)($this->tenant, 'automation.assignment', ['enabled' => false]);
    tenancy()->initialize($this->tenant);

    $id = $this->postJson('/v1/tickets', $payload)->assertCreated()
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.assigned_agent_id', null)->json('data.id');
    expect(TicketAssignment::query()->withoutTenancy()->where('ticket_id', $id)->exists())->toBeFalse();
});

it('stores the settings version with an assignment', function (): void {
    $agent = actingAsRole($this->tenant, 'agent');
    AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $agent->id, 'capacity' => 5, 'availability' => 'available']);
    ($this->configure)($this->tenant, 'tickets', ['reopen_window_days' => 10]);
    $payload = ($this->payload)($this->tenant);
    tenancy()->initialize($this->tenant);

    $id = $this->postJson('/v1/tickets', $payload)->assertCreated()->json('data.id');

    expect(TicketAssignment::query()->withoutTenancy()->where('ticket_id', $id)->sole()->getAttribute('settings_version'))->toBe(1);
});

it('uses the workspace reopen window', function (): void {
    actingAsRole($this->tenant, 'manager');
    $ticket = Ticket::factory()->forTenant($this->tenant)->create([
        'status' => TicketStatus::Resolved, 'resolved_at' => '2026-09-18 09:00:00', // three days ago
    ]);
    ($this->configure)($this->tenant, 'tickets', ['reopen_window_days' => 2]);
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_transition')->assertJsonPath('meta.reason', 'reopen_window_expired');

    ($this->configure)($this->tenant, 'tickets', ['reopen_window_days' => 5]);
    tenancy()->initialize($this->tenant);

    $this->postJson("/v1/tickets/{$ticket->id}/transition", ['status' => 'in_progress'])->assertOk();
});

it('starts no first-response timer for an agent-created ticket when the workspace says so', function (): void {
    actingAsRole($this->tenant, 'agent');
    $payload = ($this->payload)($this->tenant);
    ($this->configure)($this->tenant, 'sla', ['first_response_applies_to_agent_created' => false]);
    tenancy()->initialize($this->tenant);

    $id = $this->postJson('/v1/tickets', $payload)->assertCreated()->json('data.id');

    expect(TicketSlaTimer::query()->withoutTenancy()->where('ticket_id', $id)->pluck('kind')->map(fn ($kind) => is_object($kind) ? $kind->value : $kind)->all())
        ->toBe(['resolution']);
});

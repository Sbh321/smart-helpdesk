<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = createTenant('agents-api');
});

it('lets a manager create an agent with skills and teams and exposes workload', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $user = createTenantUser($this->tenant);
        $skill = Skill::factory()->create();
        $team = Team::factory()->create();

        $response = $this->postJson('/v1/agents', [
            'user_id' => $user->id,
            'capacity' => 12,
            'availability' => 'available',
            'skills' => [['skill_id' => $skill->id, 'level' => 4]],
            'team_ids' => [$team->id],
        ])->assertCreated()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.skills.0.level', 4)
            ->assertJsonPath('data.teams.0.id', $team->id);

        $this->getJson('/v1/agents/'.$response->json('data.id').'/workload')
            ->assertOk()
            ->assertJsonPath('data.capacity', 12)
            ->assertJsonPath('data.active_ticket_count', 0);
    });
});

it('lets an agent change only their own availability and includes the profile in me', function (): void {
    $user = actingAsRole($this->tenant, 'agent');
    $agent = AgentProfile::factory()->forTenant($this->tenant)->create(['user_id' => $user->id]);

    $this->patchJson("/v1/agents/{$agent->id}", ['availability' => 'away'])
        ->assertOk()
        ->assertJsonPath('data.availability', 'away');

    $this->patchJson("/v1/agents/{$agent->id}", ['capacity' => 50])->assertForbidden();

    $this->getJson('/v1/me')
        ->assertOk()
        ->assertJsonPath('data.agent_profile.id', $agent->id)
        ->assertJsonPath('data.agent_profile.availability', 'away');
});

it('lists only users without an agent profile as available users', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $available = createTenantUser($this->tenant, ['name' => 'Available User']);
        $taken = createTenantUser($this->tenant, ['name' => 'Taken User']);
        AgentProfile::factory()->create(['user_id' => $taken->id]);

        $this->getJson('/v1/agents/available-users')
            ->assertOk()
            ->assertJsonFragment(['id' => $available->id])
            ->assertJsonMissing(['id' => $taken->id]);
    });
});

it('deletes an unused agent profile but refuses one referenced by a ticket', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $unused = AgentProfile::factory()->create();
        $assigned = AgentProfile::factory()->create();
        Ticket::factory()->create(['assigned_agent_id' => $assigned->id]);

        $this->deleteJson('/v1/agents/'.$assigned->id)
            ->assertConflict()
            ->assertJsonPath('code', 'in_use')
            ->assertJsonPath('meta.references.tickets', 1);
        $this->assertDatabaseHas('agent_profiles', ['id' => $assigned->id]);

        $this->deleteJson('/v1/agents/'.$unused->id)->assertNoContent();
        $this->assertDatabaseMissing('agent_profiles', ['id' => $unused->id]);
    });
});

it('lists the stored workload counter and answers the live breakdown on the workload endpoint', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        // The counter is maintained by Automation; a drifted value shows the two sources apart.
        $agent = AgentProfile::factory()->create(['active_ticket_count' => 5, 'capacity' => 10]);
        Ticket::factory()->status(TicketStatus::Assigned)->create(['assigned_agent_id' => $agent->id]);
        Ticket::factory()->status(TicketStatus::Pending)->create(['assigned_agent_id' => $agent->id]);
        Ticket::factory()->status(TicketStatus::Resolved)->create(['assigned_agent_id' => $agent->id]);

        $this->getJson('/v1/agents')->assertOk()->assertJsonPath('data.0.active_ticket_count', 5);
        $this->getJson('/v1/agents/'.$agent->id)->assertOk()->assertJsonPath('data.active_ticket_count', 5);
        $this->getJson('/v1/agents/'.$agent->id.'/workload')->assertOk()
            ->assertJsonPath('data.active_ticket_count', 2)
            ->assertJsonPath('data.capacity', 10)
            ->assertJsonPath('data.load', 0.2);
    });
});

it('never moves a profile to another user', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $agent = AgentProfile::factory()->create();
        $other = createTenantUser($this->tenant);

        $this->patchJson('/v1/agents/'.$agent->id, ['user_id' => $other->id, 'capacity' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        expect($agent->refresh()->user_id)->not->toBe($other->id);
    });
});

it('stamps joined_at from the clock for new team memberships only', function (): void {
    actingAsRole($this->tenant, 'manager');
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 08:00:00'));

    $this->tenant->run(function (): void {
        $agent = AgentProfile::factory()->create();
        $kept = Team::factory()->create();
        $added = Team::factory()->create();
        $agent->teams()->attach($kept->id, ['tenant_id' => $this->tenant->id, 'joined_at' => '2026-01-05 10:00:00+00']);

        $this->patchJson('/v1/agents/'.$agent->id, ['team_ids' => [$kept->id, $added->id]])->assertOk();

        $joined = DB::table('team_members')->where('agent_profile_id', $agent->id)->pluck('joined_at', 'team_id')
            ->map(fn (string $at): string => CarbonImmutable::parse($at)->utc()->toDateTimeString());
        expect($joined[$kept->id])->toBe('2026-01-05 10:00:00')
            ->and($joined[$added->id])->toBe('2026-09-21 08:00:00');
    });
});

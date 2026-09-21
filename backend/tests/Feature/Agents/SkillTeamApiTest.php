<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Audit\Models\AuditLog;

beforeEach(function (): void {
    $this->tenant = createTenant('directory-api');
});

it('manages skills and paginates the tenant catalogue', function (): void {
    actingAsRole($this->tenant, 'manager');

    $created = $this->postJson('/v1/skills', [
        'name' => 'Billing',
        'slug' => 'billing',
        'description' => 'Billing and refunds',
    ])->assertCreated()->assertJsonPath('data.slug', 'billing');

    $this->getJson('/v1/skills?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.id', $created->json('data.id'))
        ->assertJsonPath('meta.per_page', 1);
});

it('replaces team membership atomically and audits the old and new agent ids', function (): void {
    $actor = actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function () use ($actor): void {
        $team = Team::factory()->create();
        $old = AgentProfile::factory()->create();
        $next = AgentProfile::factory()->create();
        $team->agents()->attach($old->id, ['tenant_id' => $this->tenant->id, 'joined_at' => now()]);

        $this->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => [$next->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.id', $next->id);

        expect($team->agents()->pluck('agent_profiles.id')->all())->toBe([$next->id]);

        $audit = AuditLog::query()->where('action', 'team.members_changed')->latest('created_at')->firstOrFail();
        expect($audit->actor_id)->toBe($actor->id)
            ->and($audit->changes)->toMatchArray(['old' => [$old->id], 'new' => [$next->id]]);
    });
});

it('never accepts an agent from another workspace as a team member', function (): void {
    $other = createTenant('other-directory');
    $foreignAgent = AgentProfile::factory()->forTenant($other)->create();
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function () use ($foreignAgent): void {
        $team = Team::factory()->create();

        $this->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => [$foreignAgent->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agent_ids.0');
    });
});

<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = createTenant('guards-a');
    $this->other = createTenant('guards-b');
});

it('answers 404 for directory items of another workspace', function (): void {
    [$agent, $skill, $team] = $this->other->run(fn (): array => [
        AgentProfile::factory()->create(),
        Skill::factory()->create(),
        Team::factory()->create(),
    ]);
    actingAsRole($this->tenant, 'manager');

    $this->getJson("/v1/agents/{$agent->id}")->assertNotFound();
    $this->patchJson("/v1/agents/{$agent->id}", ['capacity' => 3])->assertNotFound();
    $this->deleteJson("/v1/agents/{$agent->id}")->assertNotFound();
    $this->getJson("/v1/agents/{$agent->id}/workload")->assertNotFound();
    $this->getJson("/v1/agents/{$agent->id}/shifts")->assertNotFound();
    $this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => []])->assertNotFound();
    $this->patchJson("/v1/skills/{$skill->id}", ['name' => 'Taken', 'slug' => 'taken'])->assertNotFound();
    $this->deleteJson("/v1/skills/{$skill->id}")->assertNotFound();
    $this->patchJson("/v1/teams/{$team->id}", ['name' => 'Taken'])->assertNotFound();
    $this->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => []])->assertNotFound();
    $this->deleteJson("/v1/teams/{$team->id}")->assertNotFound();

    $this->getJson('/v1/agents')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/v1/skills')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/v1/teams')->assertOk()->assertJsonCount(0, 'data');

    $this->other->run(function () use ($agent, $skill, $team): void {
        expect(AgentProfile::query()->whereKey($agent->id)->exists())->toBeTrue()
            ->and(Skill::query()->whereKey($skill->id)->value('slug'))->not->toBe('taken')
            ->and(Team::query()->whereKey($team->id)->exists())->toBeTrue();
    });
});

it('guards directory writes with their permissions', function (string $role, int $status): void {
    [$agent, $skill, $team, $user] = $this->tenant->run(fn (): array => [
        AgentProfile::factory()->create(),
        Skill::factory()->create(),
        Team::factory()->create(),
        createTenantUser($this->tenant),
    ]);
    actingAsRole($this->tenant, $role);

    // agents.view: every workspace role that works tickets can read the directory.
    $this->getJson('/v1/agents')->assertOk();
    $this->getJson('/v1/skills')->assertOk();
    $this->getJson('/v1/teams')->assertOk();

    // agents.manage
    expect($this->getJson('/v1/agents/available-users')->status())->toBe($status === 403 ? 403 : 200);
    expect($this->postJson('/v1/agents', ['user_id' => $user->id, 'capacity' => 5, 'availability' => 'available'])->status())
        ->toBe($status === 403 ? 403 : 201);
    expect($this->patchJson("/v1/agents/{$agent->id}", ['capacity' => 7])->status())->toBe($status);
    expect($this->postJson('/v1/skills', ['name' => 'Refunds', 'slug' => 'refunds'])->status())->toBe($status === 403 ? 403 : 201);
    expect($this->patchJson("/v1/skills/{$skill->id}", ['name' => 'Renamed', 'slug' => 'renamed'])->status())->toBe($status);
    // teams.manage
    expect($this->postJson('/v1/teams', ['name' => 'Tier 2'])->status())->toBe($status === 403 ? 403 : 201);
    expect($this->patchJson("/v1/teams/{$team->id}", ['name' => 'Renamed'])->status())->toBe($status);
    expect($this->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => [$agent->id]])->status())->toBe($status);
    // shifts.manage
    expect($this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => []])->status())->toBe($status);
    expect($this->getJson("/v1/agents/{$agent->id}/shifts")->status())->toBe($status);
    // destructive, last
    expect($this->deleteJson("/v1/teams/{$team->id}")->status())->toBe($status === 403 ? 403 : 409);
    expect($this->deleteJson("/v1/skills/{$skill->id}")->status())->toBe($status === 403 ? 403 : 204);
    expect($this->deleteJson("/v1/agents/{$agent->id}")->status())->toBe($status === 403 ? 403 : 204);
})->with([
    'agent role' => ['agent', 403],
    'manager role' => ['manager', 200],
    'admin role' => ['admin', 200],
]);

it('refuses to delete a team that is still referenced, with the reference counts', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $team = Team::factory()->create();
        $agent = AgentProfile::factory()->create();
        $team->agents()->attach($agent->id, ['tenant_id' => $this->tenant->id, 'joined_at' => '2026-09-01 00:00:00+00']);
        $category = Category::factory()->create(['default_team_id' => $team->id]);
        Ticket::factory()->count(2)->create(['category_id' => $category->id, 'team_id' => $team->id]);

        $this->deleteJson("/v1/teams/{$team->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'in_use')
            ->assertJsonPath('meta.resource', 'team')
            ->assertJsonPath('meta.references', ['members' => 1, 'tickets' => 2, 'categories' => 1]);
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    });
});

it('refuses to delete a skill held by an agent or required by a category', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->tenant->run(function (): void {
        $held = Skill::factory()->create();
        AgentProfile::factory()->create()->skills()->attach($held->id, ['tenant_id' => $this->tenant->id, 'level' => 3]);
        $required = Skill::factory()->create();
        Category::factory()->create()->skills()->attach($required->id, ['tenant_id' => $this->tenant->id]);

        $this->deleteJson("/v1/skills/{$held->id}")->assertConflict()
            ->assertJsonPath('code', 'in_use')->assertJsonPath('meta.references', ['agents' => 1]);
        $this->deleteJson("/v1/skills/{$required->id}")->assertConflict()
            ->assertJsonPath('code', 'in_use')->assertJsonPath('meta.references', ['categories' => 1]);
    });
});

it('keeps joined_at of members that stay when a team roster is replaced', function (): void {
    actingAsRole($this->tenant, 'manager');
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 08:00:00'));

    $this->tenant->run(function (): void {
        $team = Team::factory()->create();
        [$stays, $leaves, $joins] = AgentProfile::factory()->count(3)->create()->all();
        foreach ([$stays, $leaves] as $agent) {
            $team->agents()->attach($agent->id, ['tenant_id' => $this->tenant->id, 'joined_at' => '2026-01-05 10:00:00+00']);
        }

        $this->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => [$stays->id, $joins->id]])->assertOk();

        $joined = DB::table('team_members')->where('team_id', $team->id)->pluck('joined_at', 'agent_profile_id')
            ->map(fn (string $at): string => CarbonImmutable::parse($at)->utc()->toDateTimeString());
        expect($joined->keys()->sort()->values()->all())->toBe(collect([$stays->id, $joins->id])->sort()->values()->all())
            ->and($joined[$stays->id])->toBe('2026-01-05 10:00:00')
            ->and($joined[$joins->id])->toBe('2026-09-21 08:00:00');
    });
});

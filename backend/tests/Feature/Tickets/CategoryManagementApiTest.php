<?php

declare(strict_types=1);

use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;

beforeEach(function (): void {
    $this->tenant = createTenant('category-api');
});

it('creates and updates categories with a default team and required skills', function (): void {
    actingAsRole($this->tenant, 'admin');

    $this->tenant->run(function (): void {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create();

        $response = $this->postJson('/v1/categories', [
            'name' => 'Billing',
            'default_team_id' => $team->id,
            'skill_ids' => [$skill->id],
            'is_active' => true,
            'sort_order' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.default_team.id', $team->id)
            ->assertJsonCount(1, 'data.required_skills');

        $this->patchJson('/v1/categories/'.$response->json('data.id'), [
            'name' => 'Accounts',
            'skill_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Accounts')
            ->assertJsonCount(0, 'data.required_skills');
    });
});

it('keeps category management behind settings permission', function (): void {
    actingAsRole($this->tenant, 'manager');

    $this->postJson('/v1/categories', ['name' => 'Forbidden'])->assertForbidden();
});

it('deletes an unused category but refuses one referenced by a ticket', function (): void {
    actingAsRole($this->tenant, 'admin');

    $this->tenant->run(function (): void {
        $unused = Category::factory()->create();
        $used = Category::factory()->create();
        Ticket::factory()->create(['category_id' => $used->id]);

        $this->deleteJson('/v1/categories/'.$used->id)
            ->assertConflict()
            ->assertJsonPath('code', 'conflict');
        $this->assertDatabaseHas('categories', ['id' => $used->id]);

        $this->deleteJson('/v1/categories/'.$unused->id)->assertNoContent();
        $this->assertDatabaseMissing('categories', ['id' => $unused->id]);
    });
});

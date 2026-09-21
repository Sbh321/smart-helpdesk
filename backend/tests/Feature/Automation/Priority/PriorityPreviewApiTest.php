<?php

declare(strict_types=1);

use App\Modules\Tickets\Models\Ticket;

// POST /v1/settings/automation/priority/preview: scores samples with unsaved settings, stores nothing.

beforeEach(function (): void {
    $this->tenant = createTenant('preview');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function previewPayload(array $overrides = []): array
{
    return [
        'weights' => ['impact' => 0.40, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10],
        'thresholds' => ['P1' => 75, 'P2' => 50, 'P3' => 25],
        'age_full_hours' => 72,
        'samples' => [
            ['impact' => 4, 'urgency' => 4, 'tier' => 'enterprise', 'hours_waited' => 0],
            ['impact' => 3, 'urgency' => 2, 'tier' => 'standard', 'hours_waited' => 0],
            ['impact' => 2, 'urgency' => 3, 'tier' => 'premium', 'hours_waited' => 24],
            ['impact' => 2, 'urgency' => 3, 'tier' => 'premium', 'hours_waited' => 72],
            ['impact' => 1, 'urgency' => 1, 'tier' => 'standard', 'hours_waited' => 0],
        ],
        ...$overrides,
    ];
}

it('scores the worked examples with the default settings', function (): void {
    actingAsRole($this->tenant, 'admin');

    $response = $this->postJson('/v1/settings/automation/priority/preview', previewPayload())->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.strategy', 'basic_weighted_priority')
        ->assertJsonPath('data.0.strategy_version', '1.0.0')
        ->assertJsonPath('data.2.parts.3.name', 'age')
        ->assertJsonPath('data.2.settings.age_full_hours', 72);

    expect(array_map(floatval(...), array_column($response->json('data'), 'score')))->toBe([90.0, 38.3, 47.5, 54.2, 0.0])
        ->and(array_column($response->json('data'), 'level'))->toBe(['P1', 'P3', 'P3', 'P2', 'P4']);
});

it('applies the submitted weights and thresholds, and stores nothing', function (): void {
    actingAsRole($this->tenant, 'admin');

    // Urgency alone decides: 100 × 1.0 × (3 − 1) ÷ 3 = 66.7; with P1 at 60 that is P1.
    $this->postJson('/v1/settings/automation/priority/preview', previewPayload([
        'weights' => ['impact' => 0, 'urgency' => 1, 'tier' => 0, 'age' => 0],
        'thresholds' => ['P1' => 60, 'P2' => 40, 'P3' => 20],
        'samples' => [['impact' => 4, 'urgency' => 3, 'tier' => 'enterprise', 'hours_waited' => 500]],
    ]))->assertOk()
        ->assertJsonPath('data.0.score', 66.7)
        ->assertJsonPath('data.0.level', 'P1')
        ->assertJsonPath('data.0.settings.thresholds.P1', 60);

    expect(Ticket::query()->withoutTenancy()->count())->toBe(0);
    $this->assertDatabaseCount('tenant_settings', 0);
});

it('rejects settings the strategy would refuse', function (array $overrides, string $field): void {
    actingAsRole($this->tenant, 'admin');

    $this->postJson('/v1/settings/automation/priority/preview', previewPayload($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'weights do not add up to 1' => [['weights' => ['impact' => 0.5, 'urgency' => 0.5, 'tier' => 0.5, 'age' => 0.5]], 'weights'],
    'thresholds out of order' => [['thresholds' => ['P1' => 50, 'P2' => 75, 'P3' => 25]], 'weights'],
    'unknown weight key' => [['weights' => ['impact' => 0.4, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.05, 'mood' => 0.05]], 'weights'],
    'age_full_hours of zero' => [['age_full_hours' => 0], 'age_full_hours'],
    'no samples' => [['samples' => []], 'samples'],
    'impact outside 1–4' => [['samples' => [['impact' => 5, 'urgency' => 1, 'tier' => 'standard', 'hours_waited' => 0]]], 'samples.0.impact'],
    'unknown tier' => [['samples' => [['impact' => 1, 'urgency' => 1, 'tier' => 'gold', 'hours_waited' => 0]]], 'samples.0.tier'],
    'negative waiting time' => [['samples' => [['impact' => 1, 'urgency' => 1, 'tier' => 'standard', 'hours_waited' => -1]]], 'samples.0.hours_waited'],
]);

it('needs settings.manage', function (string $role, int $status): void {
    actingAsRole($this->tenant, $role);

    $this->postJson('/v1/settings/automation/priority/preview', previewPayload())->assertStatus($status);
})->with([
    'agent' => ['agent', 403],
    'manager' => ['manager', 403],
    'admin' => ['admin', 200],
]);

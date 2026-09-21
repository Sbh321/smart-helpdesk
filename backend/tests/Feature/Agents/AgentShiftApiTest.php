<?php

declare(strict_types=1);

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Audit\Models\AuditLog;

beforeEach(function (): void {
    $this->tenant = createTenant('shift-api');
});

it('lets managers replace shifts and lets the owning agent read them', function (): void {
    $manager = actingAsRole($this->tenant, 'manager');
    $agent = AgentProfile::factory()->forTenant($this->tenant)->create();

    $this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => [[
        'weekday' => 1,
        'date' => null,
        'starts_at' => '09:00',
        'ends_at' => '17:00',
        'is_off' => false,
    ]]])->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.weekday', 1);

    $audit = AuditLog::query()->where('action', 'agent.shifts_changed')->firstOrFail();
    expect($audit->actor_id)->toBe($manager->id);

    actingAsRole($this->tenant, 'agent', $agent->user);
    $this->getJson("/v1/agents/{$agent->id}/shifts")->assertOk()->assertJsonCount(1, 'data');
});

it('rejects overlapping shift buckets', function (): void {
    actingAsRole($this->tenant, 'manager');
    $agent = AgentProfile::factory()->forTenant($this->tenant)->create();

    $this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => [
        ['weekday' => 1, 'date' => null, 'starts_at' => '09:00', 'ends_at' => '17:00', 'is_off' => false],
        ['weekday' => 1, 'date' => null, 'starts_at' => '12:00', 'ends_at' => '18:00', 'is_off' => false],
    ]])->assertUnprocessable()->assertJsonValidationErrors('shifts.1.starts_at');
});

it('accepts a date exception and allows a manager to clear the schedule', function (): void {
    actingAsRole($this->tenant, 'manager');
    $agent = AgentProfile::factory()->forTenant($this->tenant)->create();

    $this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => [[
        'weekday' => null, 'date' => '2026-09-19', 'starts_at' => '09:00', 'ends_at' => '17:00', 'is_off' => true,
    ]]])->assertOk()->assertJsonPath('data.0.date', '2026-09-19');

    $this->putJson("/v1/agents/{$agent->id}/shifts", ['shifts' => []])
        ->assertOk()->assertJsonCount(0, 'data');
});

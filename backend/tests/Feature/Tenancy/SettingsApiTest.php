<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\Settings\Settings;

// GET/PATCH /v1/settings/{section} (docs/03-architecture/configuration.md, roadmap M2-01).

beforeEach(function (): void {
    $this->tenant = createTenant('settings', ['timezone' => 'UTC']);
});

it('lists every section with code defaults and version 0 before any write', function (): void {
    actingAsRole($this->tenant, 'admin');

    $sections = $this->getJson('/v1/settings')->assertOk()->json('data');

    expect(array_column($sections, 'section'))->toBe([
        'automation.assignment', 'automation.duplicates', 'automation.priority',
        'branding', 'features', 'general', 'shifts', 'sla', 'tickets',
    ])->and(array_unique(array_column($sections, 'version')))->toBe([0]);

    $this->getJson('/v1/settings/automation.priority')->assertOk()
        ->assertJsonPath('data.values.baseline.weights.impact', 0.4)
        ->assertJsonPath('data.defaults.baseline.thresholds.P1', 75);
    $this->getJson('/v1/settings/tickets')->assertOk()
        ->assertJsonPath('data.values.reopen_window_days', 14)
        ->assertJsonMissingPath('data.values.default_categories');
    $this->getJson('/v1/settings/unknown')->assertNotFound();
});

it('merges a partial update, bumps the stored version and writes an audit entry', function (): void {
    $user = actingAsRole($this->tenant, 'admin');

    $this->patchJson('/v1/settings/automation.priority', ['baseline' => ['weights' => ['impact' => 0.5, 'urgency' => 0.25]]])
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.values.baseline.weights', ['impact' => 0.5, 'urgency' => 0.25, 'tier' => 0.15, 'age' => 0.1])
        ->assertJsonPath('data.values.baseline.age_full_hours', 72)
        ->assertJsonPath('data.defaults.baseline.weights.impact', 0.4);
    $this->patchJson('/v1/settings/tickets', ['reopen_window_days' => 3])->assertOk()->assertJsonPath('data.version', 2);

    $row = TenantSetting::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->sole();
    expect($row->version)->toBe(2)
        ->and($row->data['automation_priority']['baseline']['weights']['impact'])->toEqual(0.5)
        ->and($row->data['tickets'])->toEqual(['auto_close_days' => 7, 'reopen_window_days' => 3]);

    $audit = AuditLog::query()->where('action', 'settings.updated')->orderBy('created_at')->get();
    expect($audit)->toHaveCount(2)
        ->and($audit[0]->tenant_id)->toBe($this->tenant->id)
        ->and($audit[0]->actor_id)->toBe($user->id)
        ->and($audit[0]->changes['section'])->toBe('automation.priority')
        ->and($audit[0]->changes['old']['baseline']['weights']['impact'])->toEqual(0.4)
        ->and($audit[0]->changes['new']['baseline']['weights']['impact'])->toEqual(0.5)
        ->and($audit[0]->changes['version'])->toBe(1);
});

it('answers 422 settings_invalid with field errors for values that do not fit together', function (array $payload, string $field): void {
    actingAsRole($this->tenant, 'admin');

    $this->patchJson('/v1/settings/automation.priority', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'settings_invalid')
        ->assertJsonPath('meta.section', 'automation.priority')
        ->assertJsonStructure(['errors' => [$field]]);

    expect(TenantSetting::query()->withoutTenancy()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(0);
})->with([
    'weights sum to 1.1' => [['baseline' => ['weights' => ['impact' => 0.5]]], 'baseline.weights'],
    'thresholds not decreasing' => [['baseline' => ['thresholds' => ['P2' => 80]]], 'baseline.thresholds'],
    'unknown key' => [['baseline' => ['boost' => 2]], 'baseline.boost'],
]);

it('answers 422 validation_failed for malformed values', function (string $section, array $payload, string $field): void {
    actingAsRole($this->tenant, 'admin');

    $this->patchJson("/v1/settings/{$section}", $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'weight above 1' => ['automation.priority', ['baseline' => ['weights' => ['impact' => 1.4]]], 'baseline.weights.impact'],
    'threshold not a number' => ['automation.duplicates', ['baseline' => ['threshold' => 'high']], 'baseline.threshold'],
    'switch not boolean' => ['automation.assignment', ['enabled' => 'sometimes'], 'enabled'],
    'reopen window negative' => ['tickets', ['reopen_window_days' => -1], 'reopen_window_days'],
    'colour not hex' => ['branding', ['primary' => 'teal'], 'primary'],
    'time zone unknown' => ['general', ['timezone' => 'Mars/Olympus'], 'timezone'],
    'warning fraction too high' => ['sla', ['warning_fraction' => 0.99], 'warning_fraction'],
]);

it('saves the general section on the workspace itself', function (): void {
    actingAsRole($this->tenant, 'admin');

    $this->patchJson('/v1/settings/general', ['name' => 'Settings Desk', 'timezone' => 'Asia/Kathmandu'])
        ->assertOk()
        ->assertJsonPath('data.values', ['name' => 'Settings Desk', 'timezone' => 'Asia/Kathmandu'])
        ->assertJsonPath('data.version', 1);

    $tenant = Tenant::query()->findOrFail($this->tenant->id);
    expect($tenant->name)->toBe('Settings Desk')->and($tenant->timezone)->toBe('Asia/Kathmandu');
    $this->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.tenant.name', 'Settings Desk')
        ->assertJsonPath('data.tenant.settings_version', 1);
});

it('accepts only a ready image of the same workspace as a logo and exposes branding on /me', function (): void {
    actingAsRole($this->tenant, 'admin');
    $logo = MediaItem::factory()->forTenant($this->tenant)->create(['mime_type' => 'image/png', 'state' => 'ready']);
    $pdf = MediaItem::factory()->forTenant($this->tenant)->create(['mime_type' => 'application/pdf', 'state' => 'ready']);
    $foreign = MediaItem::factory()->forTenant(createTenant('elsewhere'))->create(['mime_type' => 'image/png', 'state' => 'ready']);
    tenancy()->initialize($this->tenant);

    $this->patchJson('/v1/settings/branding', ['logo_media_id' => $pdf->id])->assertStatus(422)->assertJsonStructure(['errors' => ['logo_media_id']]);
    $this->patchJson('/v1/settings/branding', ['logo_media_id' => $foreign->id])->assertStatus(422)->assertJsonStructure(['errors' => ['logo_media_id']]);
    $this->patchJson('/v1/settings/branding', ['primary' => '#0F766E', 'logo_media_id' => $logo->id])->assertOk();

    $this->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.tenant.branding.primary', '#0F766E')
        ->assertJsonPath('data.tenant.branding.logo_url', route('media.download', ['media' => $logo->id]))
        ->assertJsonPath('data.tenant.branding.logo_dark_url', null)
        ->assertJsonPath('data.tenant.features.exports', true);

    $this->patchJson('/v1/settings/branding', ['primary' => null, 'logo_media_id' => null])->assertOk()
        ->assertJsonPath('data.values.primary', null);
});

it('keeps settings and their cache apart per workspace', function (): void {
    $other = createTenant('other-settings');
    actingAsRole($this->tenant, 'admin');
    $this->patchJson('/v1/settings/tickets', ['reopen_window_days' => 2])->assertOk();
    tenancy()->end();

    $settings = app(Settings::class);
    expect($this->tenant->run(fn () => [$settings->get('tickets.reopen_window_days'), $settings->version()]))->toBe([2, 1])
        ->and($other->run(fn () => [$settings->get('tickets.reopen_window_days'), $settings->version()]))->toBe([14, 0])
        ->and($settings->get('tickets.reopen_window_days'))->toBe(14)
        ->and($settings->version())->toBe(0);
});

it('requires settings.manage', function (string $role, int $status): void {
    actingAsRole($this->tenant, $role);

    $this->getJson('/v1/settings')->assertStatus($status);
    $this->getJson('/v1/settings/branding')->assertStatus($status);
    $this->patchJson('/v1/settings/branding', ['primary' => '#0f766e'])->assertStatus($status);
})->with([['agent', 403], ['manager', 403], ['developer', 403], ['admin', 200], ['owner', 200]]);

it('requires a session', function (): void {
    fromSpaOrigin();
    $this->getJson(onApiHost('/v1/settings'))->assertUnauthorized();
});

<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Identity\Exceptions\LastOwner;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\LastOwnerGuard;
use App\Modules\Identity\Support\PermissionCatalogue;

beforeEach(function (): void {
    app(SyncPermissionCatalogue::class)();
    $this->acme = createTenant('acme');
    $this->owner = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $this->owner->syncRoles([PermissionCatalogue::OWNER]));
});

it('syncs the catalogue idempotently', function (): void {
    $before = Role::query()->count();

    app(SyncPermissionCatalogue::class)();

    expect(Role::query()->count())->toBe($before)
        ->and(Role::query()->whereNull('tenant_id')->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionCatalogue::roles())->keys()->sort()->values()->all());
});

it('lists the global roles with their permissions', function (): void {
    $this->getJson('/v1/roles')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.is_global', true)
        ->assertJsonPath('data.0.is_system', true);
});

it('returns the permission catalogue grouped by resource', function (): void {
    $this->getJson('/v1/permissions')
        ->assertOk()
        ->assertJsonPath('data.tickets', PermissionCatalogue::PERMISSIONS['tickets']);
});

it('creates, updates and deletes a custom role', function (): void {
    $created = $this->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view', 'tickets.update']])
        ->assertCreated()
        ->assertJsonPath('data.is_global', false)
        ->assertJsonPath('data.permissions', ['tickets.view', 'tickets.update']);

    $id = $created->json('data.id');

    $this->patchJson("/v1/roles/{$id}", ['permissions' => ['tickets.view']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['tickets.view']);

    $this->deleteJson("/v1/roles/{$id}")->assertNoContent();

    expect(Role::query()->find($id))->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['role.created', 'role.permissions_changed', 'role.deleted'])->count())->toBe(3);
});

it('refuses to change or delete a default role', function (): void {
    $role = Role::query()->whereNull('tenant_id')->where('name', PermissionCatalogue::AGENT)->sole();

    $this->patchJson("/v1/roles/{$role->id}", ['permissions' => []])->assertNotFound();
    $this->deleteJson("/v1/roles/{$role->id}")->assertNotFound();
});

it('validates custom roles', function (array $payload, string $field): void {
    $this->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view'], ...$payload])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'unknown permission' => [['permissions' => ['tickets.fly']], 'permissions.0'],
    'upper case name' => [['name' => 'Triage'], 'name'],
    'duplicate name' => [['name' => 'owner'], 'name'],
]);

it('never lets another workspace see or change a custom role', function (): void {
    $id = $this->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view']])->json('data.id');

    $globex = createTenant('globex');
    $other = actingAsTenantUser($globex);
    $globex->run(fn () => $other->syncRoles([PermissionCatalogue::OWNER]));

    $this->getJson('/v1/roles')->assertOk()->assertJsonCount(5, 'data');
    $this->patchJson("/v1/roles/{$id}", ['permissions' => []])->assertNotFound();
});

it('keeps at least one active owner', function (): void {
    $guard = app(LastOwnerGuard::class);

    expect(fn () => $this->acme->run(fn () => $guard->ensureNotLastOwner($this->owner)))
        ->toThrow(LastOwner::class);

    $second = createTenantUser($this->acme);
    $this->acme->run(fn () => $second->syncRoles([PermissionCatalogue::OWNER]));

    // With a second owner in place the same call is allowed.
    $this->acme->run(fn () => $guard->ensureNotLastOwner($this->owner));
    expect(true)->toBeTrue();
});

it('ignores users of other workspaces when counting owners', function (): void {
    $globex = createTenant('globex');
    $globexOwner = createTenantUser($globex);
    $globex->run(fn () => $globexOwner->syncRoles([PermissionCatalogue::OWNER]));

    actingAsTenantUser($this->acme, $this->owner);

    expect(fn () => $this->acme->run(fn () => app(LastOwnerGuard::class)->ensureNotLastOwner($this->owner)))
        ->toThrow(LastOwner::class);
});

it('assigns the invited role when an invitation is accepted', function (): void {
    $user = createTenantUser($this->acme, ['password' => null, 'email' => 'asha@acme.test']);
    $token = Invitation::newToken();

    tenancy()->initialize($this->acme);
    Invitation::query()->create([
        'user_id' => $user->id,
        'token_hash' => Invitation::hashToken($token),
        'role_names' => [PermissionCatalogue::MANAGER],
        'expires_at' => now()->addHours(48),
    ]);
    tenancy()->end();

    fromSpaOrigin();
    $this->postJson("/v1/auth/invitations/{$token}/accept", [
        'workspace' => 'acme',
        'password' => 'a-good-long-password',
        'password_confirmation' => 'a-good-long-password',
    ])->assertOk();

    expect($this->acme->run(fn () => $user->fresh()->hasRole(PermissionCatalogue::MANAGER)))->toBeTrue();
});

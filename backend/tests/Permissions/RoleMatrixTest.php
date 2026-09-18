<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Identity\Support\PermissionCatalogue;

/*
 * Roles × routes. Each cell is the status the role's user must get (docs/10-quality/testing.md
 * §Permission matrix). More rows are added as endpoints land.
 */

beforeEach(function (): void {
    app(SyncPermissionCatalogue::class)();
    $this->acme = createTenant('acme');
});

dataset('role matrix', [
    // role, method, route, expected status
    'owner reads roles' => [PermissionCatalogue::OWNER, 'get', '/v1/roles', 200],
    'admin reads roles' => [PermissionCatalogue::ADMIN, 'get', '/v1/roles', 200],
    'manager cannot read roles' => [PermissionCatalogue::MANAGER, 'get', '/v1/roles', 403],
    'agent cannot read roles' => [PermissionCatalogue::AGENT, 'get', '/v1/roles', 403],
    'developer cannot read roles' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/roles', 403],
    'owner reads the catalogue' => [PermissionCatalogue::OWNER, 'get', '/v1/permissions', 200],
    'agent cannot read the catalogue' => [PermissionCatalogue::AGENT, 'get', '/v1/permissions', 403],
    'agent reads own profile' => [PermissionCatalogue::AGENT, 'get', '/v1/me', 200],
]);

it('answers each role and route with the expected status', function (string $role, string $method, string $path, int $expected): void {
    $user = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $user->syncRoles([$role]));

    $this->{$method.'Json'}($path)->assertStatus($expected);
})->with('role matrix');

it('reports a forbidden request as problem details', function (): void {
    $user = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $user->syncRoles([PermissionCatalogue::AGENT]));

    $this->getJson('/v1/roles')
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'forbidden');
});

it('gives the default roles the permissions the catalogue promises', function (string $role, array $expected): void {
    $user = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $user->syncRoles([$role]));

    expect($this->acme->run(fn () => $user->fresh()->getAllPermissions())->pluck('name')->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all());
})->with(fn () => collect(PermissionCatalogue::roles())->map(fn (array $permissions, string $role) => [$role, $permissions])->all());

it('keeps role assignments inside the workspace', function (): void {
    $globex = createTenant('globex');
    $user = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $user->syncRoles([PermissionCatalogue::OWNER]));

    expect($this->acme->run(fn () => $user->fresh()->hasPermissionTo('roles.manage')))->toBeTrue();

    $globexUser = createTenantUser($globex);
    expect($globex->run(fn () => $globexUser->getAllPermissions()))->toBeEmpty();
});

it('returns the permissions of the signed-in user from /v1/me', function (): void {
    $user = actingAsTenantUser($this->acme);
    $this->acme->run(fn () => $user->syncRoles([PermissionCatalogue::AGENT]));

    $this->getJson('/v1/me')
        ->assertOk()
        ->assertJsonPath('data.permissions', collect(PermissionCatalogue::roles()[PermissionCatalogue::AGENT])->sort()->values()->all());
});

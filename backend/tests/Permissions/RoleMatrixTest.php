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
    'owner lists users' => [PermissionCatalogue::OWNER, 'get', '/v1/users', 200],
    'admin lists users' => [PermissionCatalogue::ADMIN, 'get', '/v1/users', 200],
    'manager cannot list users' => [PermissionCatalogue::MANAGER, 'get', '/v1/users', 403],
    'agent cannot list users' => [PermissionCatalogue::AGENT, 'get', '/v1/users', 403],
    'developer cannot list users' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/users', 403],
    'agent cannot invite users' => [PermissionCatalogue::AGENT, 'post', '/v1/users/invitations', 403],
    'agent reads own notifications' => [PermissionCatalogue::AGENT, 'get', '/v1/notifications', 200],
    'developer reads own notifications' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/notifications', 200],
    'admin reads settings' => [PermissionCatalogue::ADMIN, 'get', '/v1/settings', 200],
    'manager cannot read settings' => [PermissionCatalogue::MANAGER, 'get', '/v1/settings', 403],
    'agent reads own profile' => [PermissionCatalogue::AGENT, 'get', '/v1/me', 200],
    'owner lists api clients' => [PermissionCatalogue::OWNER, 'get', '/v1/api-clients', 200],
    'admin lists api clients' => [PermissionCatalogue::ADMIN, 'get', '/v1/api-clients', 200],
    'developer lists api clients' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/api-clients', 200],
    'manager cannot list api clients' => [PermissionCatalogue::MANAGER, 'get', '/v1/api-clients', 403],
    'agent cannot list api clients' => [PermissionCatalogue::AGENT, 'get', '/v1/api-clients', 403],
    'agent cannot create api clients' => [PermissionCatalogue::AGENT, 'post', '/v1/api-clients', 403],
    'agent cannot read the api scopes' => [PermissionCatalogue::AGENT, 'get', '/v1/api-clients/scopes', 403],
    // Exports (M3-09): reports.export for owner, admin and manager; someone else's or an unknown export is 404.
    'owner may read exports' => [PermissionCatalogue::OWNER, 'get', '/v1/exports/0199a000-0000-7000-8000-000000000000', 404],
    'admin may read exports' => [PermissionCatalogue::ADMIN, 'get', '/v1/exports/0199a000-0000-7000-8000-000000000000', 404],
    'manager may read exports' => [PermissionCatalogue::MANAGER, 'get', '/v1/exports/0199a000-0000-7000-8000-000000000000', 404],
    'agent cannot read exports' => [PermissionCatalogue::AGENT, 'get', '/v1/exports/0199a000-0000-7000-8000-000000000000', 403],
    'developer cannot read exports' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/exports/0199a000-0000-7000-8000-000000000000', 403],
    'manager validates a ticket export' => [PermissionCatalogue::MANAGER, 'post', '/v1/exports/tickets', 422],
    'agent cannot export tickets' => [PermissionCatalogue::AGENT, 'post', '/v1/exports/tickets', 403],
    'agent cannot export a report' => [PermissionCatalogue::AGENT, 'post', '/v1/reports/rpt-t01/exports', 403],
    'developer cannot export a report' => [PermissionCatalogue::DEVELOPER, 'post', '/v1/reports/rpt-t01/exports', 403],
    'owner lists webhooks' => [PermissionCatalogue::OWNER, 'get', '/v1/webhooks', 200],
    'admin lists webhooks' => [PermissionCatalogue::ADMIN, 'get', '/v1/webhooks', 200],
    'developer lists webhooks' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/webhooks', 200],
    'developer reads the webhook events' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/webhooks/events', 200],
    'manager cannot list webhooks' => [PermissionCatalogue::MANAGER, 'get', '/v1/webhooks', 403],
    'agent cannot list webhooks' => [PermissionCatalogue::AGENT, 'get', '/v1/webhooks', 403],
    'agent cannot create webhooks' => [PermissionCatalogue::AGENT, 'post', '/v1/webhooks', 403],
    'agent cannot read the webhook events' => [PermissionCatalogue::AGENT, 'get', '/v1/webhooks/events', 403],
    // Audit log (M3-03): audit.view for owner and admin only.
    'owner reads the audit log' => [PermissionCatalogue::OWNER, 'get', '/v1/audit-logs', 200],
    'admin reads the audit log' => [PermissionCatalogue::ADMIN, 'get', '/v1/audit-logs', 200],
    'manager cannot read the audit log' => [PermissionCatalogue::MANAGER, 'get', '/v1/audit-logs', 403],
    'agent cannot read the audit log' => [PermissionCatalogue::AGENT, 'get', '/v1/audit-logs', 403],
    'developer cannot read the audit log' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/audit-logs', 403],
    // Inbound email log (M3-19): mail.manage for owner and admin only.
    'owner reads the inbound email log' => [PermissionCatalogue::OWNER, 'get', '/v1/inbound-emails', 200],
    'admin reads the inbound email log' => [PermissionCatalogue::ADMIN, 'get', '/v1/inbound-emails', 200],
    'manager cannot read the inbound email log' => [PermissionCatalogue::MANAGER, 'get', '/v1/inbound-emails', 403],
    'agent cannot read the inbound email log' => [PermissionCatalogue::AGENT, 'get', '/v1/inbound-emails', 403],
    'developer cannot read the inbound email log' => [PermissionCatalogue::DEVELOPER, 'get', '/v1/inbound-emails', 403],
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

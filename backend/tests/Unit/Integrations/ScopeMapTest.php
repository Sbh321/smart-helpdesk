<?php

declare(strict_types=1);

use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Integrations\Domain\ScopeMap;

/*
 * The scope → permission map against the permission catalogue (docs/07-api/authentication.md
 * §Scopes → permissions).
 */

it('maps every scope to permissions that exist in the catalogue', function (): void {
    foreach (ScopeMap::MAP as $scope => $permissions) {
        expect($permissions)->not->toBeEmpty("{$scope} grants nothing");

        foreach ($permissions as $permission) {
            expect(PermissionCatalogue::exists($permission))->toBeTrue("{$scope} names unknown permission {$permission}");
        }
    }
});

it('names scopes resource:action and describes each one', function (): void {
    foreach (ScopeMap::scopes() as $scope) {
        expect($scope)->toMatch('/^[a-z]+:[a-z]+$/')
            ->and(ScopeMap::DESCRIPTIONS)->toHaveKey($scope);
    }

    expect(array_keys(ScopeMap::DESCRIPTIONS))->toBe(ScopeMap::scopes());
});

it('never grants assignment, internal notes, settings, users, roles, audit or history', function (): void {
    $granted = ScopeMap::permissionsFor(ScopeMap::scopes());

    foreach (ScopeMap::NEVER as $permission) {
        expect(PermissionCatalogue::exists($permission))->toBeTrue()
            ->and($granted)->not->toContain($permission);
    }
});

it('matches the documented table exactly', function (): void {
    expect(ScopeMap::MAP)->toBe([
        'tickets:read' => ['tickets.view'],
        'tickets:write' => ['tickets.create', 'tickets.update', 'tickets.resolve', 'tickets.close'],
        'contacts:read' => ['contacts.view'],
        'contacts:write' => ['contacts.manage'],
        'catalog:read' => ['agents.view'],
        'webhooks:manage' => ['integrations.manage'],
    ]);
});

it('maps scope lists to the union of their permissions and ignores unknown scopes', function (): void {
    expect(ScopeMap::permissionsFor(['tickets:read', 'tickets:read', 'contacts:read', 'nope:nope']))
        ->toBe(['tickets.view', 'contacts.view'])
        ->and(ScopeMap::permissionsFor([]))->toBe([])
        ->and(ScopeMap::grants(['tickets:read'], 'tickets.view'))->toBeTrue()
        ->and(ScopeMap::grants(['tickets:read'], 'tickets.create'))->toBeFalse()
        ->and(ScopeMap::exists('tickets:read'))->toBeTrue()
        ->and(ScopeMap::exists('tickets.view'))->toBeFalse();
});

it('describes every scope with its permissions for the settings screen', function (): void {
    $definitions = ScopeMap::definitions();

    expect(array_map(fn ($definition): string => $definition->scope, $definitions))->toBe(ScopeMap::scopes());

    foreach ($definitions as $definition) {
        expect($definition->permissions)->toBe(ScopeMap::MAP[$definition->scope])
            ->and($definition->description)->toBe(ScopeMap::DESCRIPTIONS[$definition->scope]);
    }
});

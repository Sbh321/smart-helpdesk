<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain;

/**
 * OAuth scopes of API clients and the permissions each one grants
 * (docs/07-api/authentication.md §Scopes → permissions).
 *
 * The map is static on purpose: a scope is a promise to integrators, so widening one is a
 * reviewed code change, never a setting. Scopes never grant `tickets.assign`, `comments.internal`,
 * settings, users, roles or audit access; `tests/Unit/Integrations/ScopeMapTest.php` holds the map
 * against `PermissionCatalogue`.
 */
final class ScopeMap
{
    /** @var array<string, list<string>> scope => permissions */
    public const MAP = [
        'tickets:read' => ['tickets.view'],
        'tickets:write' => ['tickets.create', 'tickets.update', 'tickets.resolve', 'tickets.close'],
        'contacts:read' => ['contacts.view'],
        'contacts:write' => ['contacts.manage'],
        'catalog:read' => ['agents.view'],
        'webhooks:manage' => ['integrations.manage'],
    ];

    /** @var array<string, string> scope => description shown in the SPA and the OpenAPI document */
    public const DESCRIPTIONS = [
        'tickets:read' => 'Read tickets, their history and public comments',
        'tickets:write' => 'Create tickets',
        'contacts:read' => 'Read contacts and organisations',
        'contacts:write' => 'Create and update contacts and organisations',
        'catalog:read' => 'Read agents, teams, categories and SLA policies',
        'webhooks:manage' => 'Manage webhook subscriptions',
    ];

    /** Permissions no scope may ever grant (authentication.md §3). */
    public const NEVER = [
        'tickets.assign', 'tickets.delete', 'comments.internal', 'settings.manage', 'users.manage',
        'roles.manage', 'audit.view', 'history.view',
    ];

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * @return list<ScopeDefinition>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (self::MAP as $scope => $permissions) {
            $definitions[] = new ScopeDefinition($scope, self::DESCRIPTIONS[$scope], $permissions);
        }

        return $definitions;
    }

    public static function exists(string $scope): bool
    {
        return array_key_exists($scope, self::MAP);
    }

    /**
     * @param  iterable<string>  $scopes
     * @return list<string> the permissions the scopes grant, unknown scopes ignored
     */
    public static function permissionsFor(iterable $scopes): array
    {
        $permissions = [];

        foreach ($scopes as $scope) {
            foreach (self::MAP[$scope] ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    /**
     * @param  iterable<string>  $scopes
     */
    public static function grants(iterable $scopes, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($scopes), true);
    }
}

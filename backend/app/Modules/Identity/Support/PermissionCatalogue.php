<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * The permission vocabulary and the global default roles (ADR-0007 §4).
 *
 * Code always checks permissions, never roles. Adding a permission here and running
 * `php artisan identity:sync-permissions` is the only way to introduce one.
 */
final class PermissionCatalogue
{
    public const GUARD = 'web';

    /** @var array<string, list<string>> resource => actions */
    public const PERMISSIONS = [
        'tickets' => ['view', 'create', 'update', 'assign', 'resolve', 'close', 'reopen', 'delete'],
        'comments' => ['internal'],
        'contacts' => ['view', 'manage'],
        'agents' => ['view', 'manage'],
        'teams' => ['manage'],
        'sla' => ['manage'],
        'calendars' => ['manage'],
        'shifts' => ['manage'],
        'media' => ['view', 'upload', 'manage'],
        'mail' => ['manage'],
        'settings' => ['manage'],
        'users' => ['manage'],
        'roles' => ['manage'],
        'integrations' => ['manage'],
        'reports' => ['view'],
        // Entity change logs and as-of views (ADR-0022 §8, security.md §History).
        'history' => ['view'],
        'audit' => ['view'],
    ];

    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const AGENT = 'agent';

    public const DEVELOPER = 'developer';

    /**
     * Global default roles. `owner` gets everything; the others are subsets.
     *
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        $all = self::all();

        $agent = [
            'tickets.view', 'tickets.create', 'tickets.update', 'tickets.resolve', 'tickets.close', 'tickets.reopen',
            'comments.internal', 'contacts.view', 'contacts.manage', 'agents.view',
            'media.view', 'media.upload', 'reports.view',
        ];

        $manager = [
            ...$agent,
            'tickets.assign', 'tickets.delete', 'agents.manage', 'teams.manage', 'sla.manage',
            'calendars.manage', 'shifts.manage', 'media.manage', 'history.view',
        ];

        $admin = [
            ...$manager,
            'settings.manage', 'users.manage', 'roles.manage', 'integrations.manage', 'mail.manage', 'audit.view',
        ];

        return [
            self::OWNER => $all,
            self::ADMIN => $admin,
            self::MANAGER => $manager,
            self::AGENT => $agent,
            self::DEVELOPER => ['tickets.view', 'contacts.view', 'agents.view', 'integrations.manage', 'reports.view', 'media.view'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}

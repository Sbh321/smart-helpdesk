<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Exceptions\RoleNotGrantable;
use App\Modules\Identity\Models\Role;

/**
 * Who may give or take which roles (docs/03-architecture/security.md §Role assignment). `users.manage`
 * lets a user change roles, but never beyond their own reach:
 *
 *  1. Only an owner grants or removes the `owner` role. Owner and admin hold the same permissions, so
 *     without this rule an admin could make themselves owner.
 *  2. A role may be given or taken only when every one of its permissions is one the actor holds.
 */
final class RoleAssignmentGuard
{
    /**
     * @param  list<string>  $before  role names the target has now
     * @param  list<string>  $after  role names the target will have
     */
    public function ensureCanChange(User $actor, array $before, array $after): void
    {
        $changed = array_values(array_unique([...array_diff($after, $before), ...array_diff($before, $after)]));
        if ($changed === []) {
            return;
        }

        if (in_array(PermissionCatalogue::OWNER, $changed, true) && ! $actor->hasRole(PermissionCatalogue::OWNER)) {
            throw RoleNotGrantable::because('Only an owner can give or take the owner role.', [PermissionCatalogue::OWNER]);
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $beyond = Role::inWorkspace()->with('permissions')->whereIn('name', $changed)->get()
            ->filter(fn (Role $role): bool => array_diff($role->permissions->pluck('name')->all(), $held) !== [])
            ->pluck('name')->values()->all();

        if ($beyond !== []) {
            throw RoleNotGrantable::because('You can only give or take roles whose permissions you hold yourself.', $beyond);
        }
    }

    /**
     * Editing a custom role reaches everyone who holds it, the actor included, so a permission may be
     * added to or removed from a role only by a user who holds it.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function ensureCanEditPermissions(User $actor, array $before, array $after): void
    {
        $changed = array_values(array_unique([...array_diff($after, $before), ...array_diff($before, $after)]));
        $beyond = array_values(array_diff($changed, $actor->getAllPermissions()->pluck('name')->all()));

        if ($beyond !== []) {
            throw RoleNotGrantable::because('You can only add or remove permissions you hold yourself.', $beyond);
        }
    }
}

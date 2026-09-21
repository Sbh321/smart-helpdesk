<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Support\LastOwnerGuard;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Identity\Support\RoleAssignmentGuard;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the roles of a user (`PATCH /v1/users/{user}`). For a user who has not accepted yet the roles
 * live on the pending invitation, so that is what changes.
 */
final readonly class ChangeUserRoles
{
    public function __construct(private RoleAssignmentGuard $guard, private LastOwnerGuard $lastOwner) {}

    /** @param list<string> $roles */
    public function __invoke(User $actor, User $user, array $roles): void
    {
        DB::transaction(function () use ($actor, $user, $roles): void {
            $pending = $user->password === null
                ? Invitation::query()->where('user_id', $user->id)->orderByDesc('created_at')->lockForUpdate()->first()
                : null;
            $before = $pending !== null ? $pending->role_names : $user->getRoleNames()->values()->all();
            if (array_diff($before, $roles) === [] && array_diff($roles, $before) === []) {
                return;
            }

            $this->guard->ensureCanChange($actor, $before, $roles);
            if (in_array(PermissionCatalogue::OWNER, $before, true) && ! in_array(PermissionCatalogue::OWNER, $roles, true)) {
                $this->lastOwner->ensureNotLastOwner($user);
            }

            if ($pending !== null) {
                $pending->forceFill(['role_names' => $roles])->save();
            } else {
                $user->syncRoles($roles);
            }

            Audit::record('user.role_changed', $user, ['old' => ['roles' => $before], 'new' => ['roles' => $roles]]);
        });
    }
}

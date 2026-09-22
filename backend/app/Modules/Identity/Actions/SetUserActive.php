<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\UserStateConflict;
use App\Modules\Identity\Support\LastOwnerGuard;
use App\Modules\Identity\Support\RoleAssignmentGuard;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Disables or enables a user. A disabled user cannot sign in, loses every session and token at once,
 * and is skipped by automatic assignment; their tickets, comments and history stay.
 */
final readonly class SetUserActive
{
    public function __construct(private LastOwnerGuard $lastOwner, private RoleAssignmentGuard $guard, private Clock $clock) {}

    public function __invoke(User $actor, User $user, bool $active): User
    {
        if ($user->is_active === $active) {
            return $user;
        }
        if ($actor->is($user)) {
            throw UserStateConflict::because('self', 'You cannot disable or enable your own account.');
        }

        DB::transaction(function () use ($actor, $user, $active): void {
            // Disabling someone takes their roles out of use: the same reach rule as taking them away.
            $this->guard->ensureCanChange($actor, $user->getRoleNames()->values()->all(), []);
            if (! $active) {
                $this->lastOwner->ensureNotLastOwner($user);
            }

            $user->forceFill(['is_active' => $active, 'disabled_at' => $active ? null : $this->clock->now()])->save();
            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            }
        });

        Audit::record($active ? 'user.enabled' : 'user.disabled', $user, ['is_active' => ['old' => ! $active, 'new' => $active]]);

        return $user;
    }
}

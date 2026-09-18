<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Exceptions\LastOwner;
use Illuminate\Database\Eloquent\Builder;

/**
 * A workspace must keep at least one active owner, so nobody can lock everyone out
 * (docs/03-architecture/security.md). Checked before removing the role and before disabling a user.
 */
final class LastOwnerGuard
{
    public function ensureNotLastOwner(User $user): void
    {
        if (! $user->hasRole(PermissionCatalogue::OWNER)) {
            return;
        }

        $otherOwners = User::query()
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where('name', PermissionCatalogue::OWNER))
            ->count();

        if ($otherOwners === 0) {
            throw LastOwner::make();
        }
    }
}

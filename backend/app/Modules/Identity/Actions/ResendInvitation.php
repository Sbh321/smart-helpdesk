<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\UserStateConflict;
use App\Modules\Identity\Models\Invitation;
use Illuminate\Support\Facades\DB;

/**
 * A new link for a user who has not accepted yet (the old one may have expired or been lost). The roles
 * stay those of the latest invitation.
 */
final readonly class ResendInvitation
{
    public function __construct(private InviteUser $invite) {}

    public function __invoke(User $actor, User $user): Invitation
    {
        if ($user->password !== null) {
            throw UserStateConflict::because('already_accepted', 'This user has already accepted an invitation.');
        }
        if (! $user->is_active) {
            throw UserStateConflict::because('disabled', 'Enable the user before inviting them again.');
        }

        $invitation = DB::transaction(function () use ($actor, $user): Invitation {
            $latest = Invitation::query()->where('user_id', $user->id)->orderByDesc('created_at')->lockForUpdate()->first();

            return $this->invite->issue($user, $actor, $latest === null ? [] : $latest->role_names);
        });

        Audit::record('user.invitation_resent', $user);

        return $invitation;
    }
}

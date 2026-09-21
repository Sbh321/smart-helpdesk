<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Identity\Support\RoleAssignmentGuard;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Adds a user to the workspace (docs/07-api/authentication.md §2): an account without a password and a
 * single-use, 48-hour invitation carrying the roles. The roles are given when the invitation is
 * accepted; until then the user is listed as invited.
 */
final readonly class InviteUser
{
    public const int INVITATION_HOURS = 48;

    public function __construct(private Clock $clock, private RoleAssignmentGuard $guard) {}

    /** @param list<string> $roles */
    public function __invoke(User $actor, string $name, string $email, array $roles): User
    {
        $this->guard->ensureCanChange($actor, [], $roles);

        $user = DB::transaction(function () use ($actor, $name, $email, $roles): User {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => mb_strtolower(trim($email)),
                'password' => null,
                'is_active' => true,
                'preferences' => [],
            ]);
            $this->issue($user, $actor, $roles);

            return $user;
        });

        Audit::record('user.invited', $user, ['email' => $user->email, 'roles' => $roles]);

        return $user;
    }

    /**
     * Writes a fresh invitation and mails its link. Earlier pending invitations of the user stop working.
     *
     * @param  list<string>  $roles
     */
    public function issue(User $user, User $actor, array $roles): Invitation
    {
        $now = $this->clock->now();
        Invitation::query()->where('user_id', $user->id)->whereNull('accepted_at')->where('expires_at', '>', $now)
            ->update(['expires_at' => $now]);

        $token = Invitation::newToken();
        $invitation = Invitation::query()->create([
            'user_id' => $user->id,
            'invited_by_user_id' => $actor->id,
            'token_hash' => Invitation::hashToken($token),
            'role_names' => $roles,
            'expires_at' => $now->addHours(self::INVITATION_HOURS),
        ]);

        $user->notify(new UserInvitation($token, (string) tenant('slug'), (string) tenant('name')));

        return $invitation;
    }
}

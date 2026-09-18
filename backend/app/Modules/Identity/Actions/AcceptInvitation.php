<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Exceptions\InvalidCredentials;
use App\Modules\Tenancy\Support\TenantResolver;
use App\Support\Time\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sets the password of a pre-created user and signs them in (docs/07-api/authentication.md §2).
 * The token is single use, hashed in the database and valid for 48 hours.
 */
final readonly class AcceptInvitation
{
    public function __construct(private Clock $clock) {}

    public function __invoke(Request $request, string $token, string $password, ?string $name = null): User
    {
        $now = $this->clock->now();

        $user = DB::transaction(function () use ($token, $password, $name, $now): User {
            $invitation = Invitation::query()
                ->where('token_hash', Invitation::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isPending($now)) {
                throw InvalidCredentials::make();
            }

            $user = $invitation->user()->firstOrFail();
            $user->forceFill([
                'password' => $password,
                'name' => $name ?? $user->name,
                'email_verified_at' => $user->email_verified_at ?? $now,
                'is_active' => true,
            ])->save();

            $invitation->forceFill(['accepted_at' => $now])->save();

            // Roles that were deleted between invitation and acceptance are skipped, so a stale
            // invitation still works; the user simply starts without that role.
            $roles = Role::query()
                ->whereIn('name', $invitation->role_names)
                ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $invitation->tenant_id))
                ->pluck('name')
                ->all();

            if ($roles !== []) {
                $user->syncRoles($roles);
            }

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(TenantResolver::SESSION_KEY, $user->tenant_id);

        Audit::record('user.invitation_accepted', $user);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Support\LoginThrottle;
use App\Modules\Tenancy\Exceptions\InvalidCredentials;
use App\Modules\Tenancy\Support\TenantResolver;
use App\Support\Time\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Signs a user in inside the already-initialised tenant (docs/07-api/authentication.md §1).
 * Unknown workspace, unknown email and wrong password all answer 401 invalid_credentials.
 */
final readonly class AuthenticateUser
{
    public function __construct(
        private LoginThrottle $throttle,
        private Clock $clock,
    ) {}

    public function __invoke(Request $request, string $email, string $password, bool $remember = false): User
    {
        $tenant = tenant();
        $tenantId = (string) $tenant?->getTenantKey();

        $this->throttle->ensureNotLocked($tenantId, $email);

        $user = User::query()->whereRaw('lower(email) = lower(?)', [$email])->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password) || ! $user->is_active) {
            $this->throttle->recordFailure($tenantId, $email);
            Audit::record('user.login_failed', changes: ['email' => $email, 'reason' => $user === null ? 'unknown_email' : 'invalid_password']);

            throw InvalidCredentials::make();
        }

        $this->throttle->clear($tenantId, $email);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put(TenantResolver::SESSION_KEY, $tenantId);

        $user->forceFill(['last_login_at' => $this->clock->now()])->save();
        Audit::record('user.logged_in', $user);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Tenancy\Exceptions\InvalidCredentials;
use App\Support\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Platform super admin sign-in on the admin host. A separate guard and cookie from tenant users
 * (ADR-0021 §Cookies). MFA is a V1 item.
 */
final class PlatformAuthController
{
    /**
     * Sign in as a platform super admin.
     *
     * @unauthenticated
     */
    public function login(Request $request, Clock $clock): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = PlatformUser::query()->whereRaw('lower(email) = lower(?)', [$credentials['email']])->first();

        if ($user === null || ! Hash::check($credentials['password'], (string) $user->password)) {
            throw InvalidCredentials::make();
        }

        Auth::guard('platform')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => $clock->now()])->save();

        Audit::record('platform_user.logged_in', $user, tenantId: null, actorType: ActorType::PlatformUser, actorId: $user->getKey());

        return new JsonResponse(['data' => $this->profile($user)]);
    }

    /**
     * Sign out of the platform console.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(status: 204);
    }

    /**
     * The signed-in platform super admin.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user('platform');

        return new JsonResponse(['data' => $this->profile($user)]);
    }

    /**
     * @return array{id: string, name: string, email: string, last_login_at: ?string}
     */
    private function profile(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}

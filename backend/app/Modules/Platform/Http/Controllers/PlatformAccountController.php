<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Platform\Models\PlatformInvitation;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Support\PlatformException;
use App\Support\Errors\ErrorCode;
use App\Support\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * A platform admin's own account (ADR-0025 §7): forgotten password, invitations, name and password.
 * The forgot-password answer is the same for every address.
 */
final class PlatformAccountController
{
    /**
     * Ask for a password reset link.
     *
     * Answers the same whether or not the address belongs to an admin who can sign in.
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:filter', 'max:254']]);
        $admin = self::findByEmail($data['email']);
        if ($admin !== null && $admin->canSignIn()) {
            Password::broker('platform_users')->sendResetLink(['email' => $admin->email]);
        }

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    /**
     * Choose a new password with a reset link.
     *
     * @unauthenticated
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $admin = self::findByEmail($data['email']);
        $status = $admin === null || ! $admin->is_active ? Password::INVALID_USER : Password::broker('platform_users')->reset(
            ['email' => $admin->email, 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']],
            function (PlatformUser $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                Audit::record('platform_user.password_reset', $user, tenantId: null, actorType: ActorType::PlatformUser, actorId: $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new PlatformException(ErrorCode::LinkExpired, 'This reset link has expired or was already used. Ask for a new one.');
        }

        return new JsonResponse(status: 204);
    }

    /**
     * Read an invitation.
     *
     * @unauthenticated
     *
     * @response array{data: array{email: string, name: string, expires_at: string}}
     */
    public function invitation(string $token, Clock $clock): JsonResponse
    {
        $invitation = self::usableInvitation($token, $clock);

        return new JsonResponse(['data' => [
            'email' => $invitation->user->email,
            'name' => $invitation->user->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    /**
     * Accept an invitation: set a name and password and sign in.
     *
     * @unauthenticated
     */
    public function acceptInvitation(Request $request, string $token, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $invitation = self::usableInvitation($token, $clock);
        $admin = $invitation->user;
        if (! $admin->is_active) {
            throw new PlatformException(ErrorCode::LinkExpired, 'This invitation is no longer valid.');
        }

        $admin->forceFill(['name' => $data['name'], 'password' => $data['password'], 'last_login_at' => $clock->now()])->save();
        $invitation->forceFill(['accepted_at' => $clock->now()])->save();
        Audit::record('platform_user.invitation_accepted', $admin, tenantId: null, actorType: ActorType::PlatformUser, actorId: $admin->id);

        Auth::guard('platform')->login($admin);
        $request->session()->regenerate();

        return new JsonResponse(['data' => PlatformAuthController::profileOf($admin)]);
    }

    /** Change your own name. */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var PlatformUser $admin */
        $admin = $request->user('platform');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $admin->forceFill(['name' => $data['name']])->save();

        return new JsonResponse(['data' => PlatformAuthController::profileOf($admin)]);
    }

    /** Change your own password. */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var PlatformUser $admin */
        $admin = $request->user('platform');
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);
        if (! Hash::check($data['current_password'], (string) $admin->password)) {
            throw ValidationException::withMessages(['current_password' => 'This is not your current password.']);
        }
        $admin->forceFill(['password' => $data['password']])->save();
        Audit::record('platform_user.password_changed', $admin, tenantId: null, actorType: ActorType::PlatformUser, actorId: $admin->id);

        return new JsonResponse(status: 204);
    }

    public static function findByEmail(string $email): ?PlatformUser
    {
        return PlatformUser::query()->whereRaw('lower(email) = lower(?)', [$email])->first();
    }

    private static function usableInvitation(string $token, Clock $clock): PlatformInvitation
    {
        return PlatformInvitation::findUsable($token, $clock->now())
            ?? throw new PlatformException(ErrorCode::LinkExpired, 'This invitation has expired or was already used. Ask an admin to send a new one.');
    }
}

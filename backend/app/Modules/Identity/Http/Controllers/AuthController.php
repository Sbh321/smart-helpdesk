<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Actions\AcceptInvitation;
use App\Modules\Identity\Actions\AuthenticateUser;
use App\Modules\Identity\Actions\ResetUserPassword;
use App\Modules\Identity\Actions\SendPasswordResetLink;
use App\Modules\Identity\Http\Requests\AcceptInvitationRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;

#[Group('Authentication')]
final class AuthController
{
    /**
     * Sign in to a workspace.
     *
     * The workspace slug selects the tenant; the session then carries it, so no later request
     * names a tenant. Unknown workspace, unknown email and wrong password answer the same way.
     *
     * @unauthenticated
     *
     * Failures answer with problem details: `invalid_credentials` (401), `tenant_suspended` (403),
     * `account_locked` (403) and `rate_limited` (429).
     */
    public function login(LoginRequest $request, AuthenticateUser $authenticate): MeResource
    {
        $user = $authenticate(
            $request,
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (bool) $request->boolean('remember'),
        );

        return new MeResource($user);
    }

    /**
     * Sign out and destroy the session.
     */
    public function logout(Request $request): HttpResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            Audit::record('user.logged_out', $user);
        }

        return response()->noContent();
    }

    /**
     * Accept an invitation and set a password.
     *
     * @unauthenticated
     *
     * An unknown, used or expired token answers `invalid_credentials` (401).
     */
    public function acceptInvitation(AcceptInvitationRequest $request, string $token, AcceptInvitation $accept): MeResource
    {
        $user = $accept(
            $request,
            $token,
            (string) $request->validated('password'),
            $request->validated('name'),
        );

        return new MeResource($user);
    }

    /**
     * Ask for a password-reset link.
     *
     * Always answers 202, whether or not the address belongs to an account.
     *
     * @unauthenticated
     */
    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetLink $send): JsonResponse
    {
        $send((string) $request->validated('email'));

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }

    /**
     * Set a new password with a reset token.
     *
     * @unauthenticated
     *
     * An unknown, used or expired token answers `invalid_credentials` (401).
     */
    public function resetPassword(ResetPasswordRequest $request, ResetUserPassword $reset): JsonResponse
    {
        $reset(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return new JsonResponse(['data' => ['status' => 'reset']]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Platform\Http\Resources\PlatformAdminResource;
use App\Modules\Platform\Models\PlatformInvitation;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Notifications\PlatformAdminInvitation;
use App\Modules\Platform\Support\PlatformException;
use App\Support\Errors\ErrorCode;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform super admins (ADR-0025 §7): all equal. Invited by email, deactivated rather than deleted;
 * nobody deactivates themselves and the last active admin stays.
 */
final class PlatformAdminController
{
    public function __construct(private readonly Clock $clock) {}

    /** List platform admins. */
    public function index(): AnonymousResourceCollection
    {
        return PlatformAdminResource::collection(PlatformUser::query()->with('invitation')->orderBy('name')->get());
    }

    /** Invite a platform admin by email. */
    #[Response(status: 201, type: PlatformAdminResource::class)]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:254'],
        ]);
        if (PlatformAccountController::findByEmail($data['email']) !== null) {
            throw ValidationException::withMessages(['email' => 'This person is already a platform admin, or was invited.']);
        }

        $admin = DB::transaction(function () use ($data, $request): PlatformUser {
            $admin = PlatformUser::query()->create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => null,
                'is_active' => true, 'invited_by_id' => $request->user('platform')?->getAuthIdentifier(),
            ]);
            Audit::record('platform_user.invited', $admin, ['email' => $admin->email], tenantId: null);

            return $admin;
        });
        $this->sendInvitation($admin, $request);

        return (new PlatformAdminResource($admin->load('invitation')))->response()->setStatusCode(201);
    }

    /** Send an invitation again, with a new link. */
    public function resend(Request $request, PlatformUser $admin): PlatformAdminResource
    {
        $this->ensureInvited($admin);
        $this->sendInvitation($admin, $request);

        return new PlatformAdminResource($admin->load('invitation'));
    }

    /** Withdraw an invitation that was not accepted. */
    public function revoke(PlatformUser $admin): JsonResponse
    {
        $this->ensureInvited($admin);
        Audit::record('platform_user.invitation_revoked', $admin, ['email' => $admin->email], tenantId: null);
        $admin->delete();

        return new JsonResponse(status: 204);
    }

    /** Deactivate an admin: they are signed out on their next request. */
    public function deactivate(Request $request, PlatformUser $admin): PlatformAdminResource
    {
        if ($admin->id === $request->user('platform')?->getAuthIdentifier()) {
            throw new PlatformException(ErrorCode::Forbidden, 'You cannot deactivate yourself. Ask another admin.');
        }
        DB::transaction(function () use ($admin): void {
            // One locked row answers "is anyone else active?" (PostgreSQL cannot lock an aggregate).
            $another = PlatformUser::query()->active()->whereKeyNot($admin->id)->lockForUpdate()->first(['id']);
            if ($admin->canSignIn() && $another === null) {
                throw new PlatformException(ErrorCode::LastAdmin, 'This is the last active platform admin.');
            }
            $admin->forceFill(['is_active' => false, 'deactivated_at' => $this->clock->now()])->save();
            PlatformInvitation::query()->where('platform_user_id', $admin->id)->whereNull('accepted_at')->delete();
        });
        Audit::record('platform_user.deactivated', $admin, tenantId: null);

        return new PlatformAdminResource($admin->load('invitation'));
    }

    /** Reactivate a deactivated admin. */
    public function reactivate(PlatformUser $admin): PlatformAdminResource
    {
        $admin->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        Audit::record('platform_user.reactivated', $admin, tenantId: null);

        return new PlatformAdminResource($admin->load('invitation'));
    }

    private function ensureInvited(PlatformUser $admin): void
    {
        if ($admin->password !== null || ! $admin->is_active) {
            throw new PlatformException(ErrorCode::Conflict, 'This admin has no open invitation.');
        }
    }

    private function sendInvitation(PlatformUser $admin, Request $request): void
    {
        PlatformInvitation::query()->where('platform_user_id', $admin->id)->whereNull('accepted_at')->delete();
        $token = PlatformInvitation::newToken();
        PlatformInvitation::query()->create([
            'platform_user_id' => $admin->id,
            'token_hash' => PlatformInvitation::hashToken($token),
            'expires_at' => $this->clock->now()->addHours(PlatformInvitation::HOURS),
            'invited_by_id' => $request->user('platform')?->getAuthIdentifier(),
        ]);
        /** @var PlatformUser|null $inviter */
        $inviter = $request->user('platform');
        $admin->notify(new PlatformAdminInvitation($token, $inviter->name ?? 'A platform admin'));
    }
}

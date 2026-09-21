<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Identity\Models\Invitation;
use App\Support\Time\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as Settings → Users shows it. `status`: `disabled`, `invited` (no password yet; the roles are
 * those of the latest invitation) or `active`. `invitation_expired` tells an invited user whose link
 * no longer works, so the SPA can offer "resend".
 *
 * @mixin User
 */
final class WorkspaceUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Invitation|null $invitation */
        $invitation = $this->password === null ? $this->resource->latestInvitation : null;
        $status = match (true) {
            ! $this->is_active => 'disabled',
            $this->password === null => 'invited',
            default => 'active',
        };
        $expired = $invitation !== null && $invitation->expires_at->lessThanOrEqualTo(app(Clock::class)->now());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $status,
            /** @var list<string> */
            'roles' => $invitation !== null ? $invitation->role_names : $this->resource->roles->pluck('name')->values()->all(),
            'invitation_expires_at' => $invitation?->expires_at->toIso8601ZuluString(),
            'invitation_expired' => (bool) $expired,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Models\PlatformInvitation;
use App\Modules\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform super admin as the console lists them.
 *
 * @mixin PlatformUser
 */
final class PlatformAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PlatformUser $admin */
        $admin = $this->resource;
        /** @var PlatformInvitation|null $invitation */
        $invitation = $admin->relationLoaded('invitation') ? $admin->getRelation('invitation') : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            /** @var 'active'|'invited'|'deactivated' */
            'status' => ! $admin->is_active ? 'deactivated' : ($admin->password === null ? 'invited' : 'active'),
            'is_you' => $request->user('platform')?->getAuthIdentifier() === $admin->id,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'invitation_expires_at' => $invitation?->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

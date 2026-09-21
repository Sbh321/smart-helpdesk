<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Agents\Http\Resources\AgentSessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the SPA needs after sign-in (docs/07-api/conventions.md).
 *
 * @mixin User
 */
final class MeResource extends JsonResource
{
    /**
     * @return array{user: UserResource, tenant: TenantResource, permissions: list<string>, agent_profile: AgentSessionResource|null, unread_notifications: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource),
            'tenant' => new TenantResource(tenant()),
            'permissions' => $this->permissionNames(),
            'agent_profile' => $this->agentProfile === null ? null : new AgentSessionResource($this->agentProfile),
            // Laravel's own relation on the user: Identity does not import the Notifications module.
            'unread_notifications' => $this->unreadNotifications()->count(),
        ];
    }

    /**
     * Flat, sorted permission names, so the SPA can check membership directly.
     *
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $names = [];

        foreach ($this->resource->getAllPermissions() as $permission) {
            $names[] = (string) $permission->name;
        }

        sort($names);

        return $names;
    }
}

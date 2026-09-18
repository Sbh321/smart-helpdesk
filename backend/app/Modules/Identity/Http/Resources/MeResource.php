<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the SPA needs after sign-in (docs/07-api/conventions.md).
 *
 * MVP-SHORTCUT: the unread count is a placeholder; V1: none (M2-09 notifications fill it).
 *
 * @mixin User
 */
final class MeResource extends JsonResource
{
    /**
     * @return array{user: UserResource, tenant: TenantResource, permissions: list<string>, unread_notifications: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource),
            'tenant' => new TenantResource(tenant()),
            'permissions' => $this->permissionNames(),
            'unread_notifications' => 0,
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

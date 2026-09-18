<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The platform view of a workspace: more than tenant users see.
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array{id: string, slug: string, name: string, status: string, plan: string, placement: string, owner_email: ?string, timezone: string, suspended_at: ?string, archived_at: ?string, created_at: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'plan' => $this->plan,
            'placement' => $this->placement,
            'owner_email' => $this->owner_email,
            'timezone' => $this->timezone,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}

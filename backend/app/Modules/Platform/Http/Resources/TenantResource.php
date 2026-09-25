<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\Subscriptions;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The platform view of a workspace: more than tenant users see, with its subscription (ADR-0025).
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * Loads the subscriptions of a page of workspaces in one query. Call it before `collection()`; a
     * single resource loads its own.
     *
     * @param  iterable<int, Tenant>  $tenants
     */
    public static function preload(iterable $tenants): void
    {
        $byTenant = [];
        foreach ($tenants as $tenant) {
            $byTenant[(string) $tenant->getKey()] = $tenant;
        }
        $subscriptions = Subscription::query()->with('plan')->whereIn('tenant_id', array_keys($byTenant))->get()->keyBy('tenant_id');
        foreach ($byTenant as $id => $tenant) {
            $tenant->setRelation('subscription', $subscriptions->get($id));
        }
    }

    public function toArray(Request $request): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->resource;
        if (! $tenant->relationLoaded('subscription')) {
            self::preload([$tenant]);
        }
        /** @var Subscription|null $subscription */
        $subscription = $tenant->getRelation('subscription');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'placement' => $this->placement,
            'owner_email' => $this->owner_email,
            'timezone' => $this->timezone,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'subscription' => new SubscriptionResource(app(Subscriptions::class)->status($subscription)),
        ];
    }
}

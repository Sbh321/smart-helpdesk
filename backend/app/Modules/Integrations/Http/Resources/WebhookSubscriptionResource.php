<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\WebhookSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A webhook subscription. The secret is never part of this shape.
 *
 * @mixin WebhookSubscription
 */
class WebhookSubscriptionResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, url: string, events: list<string>, api_version: string, is_active: bool, disabled_at: string|null, disabled_reason: string|null, consecutive_failures: int, previous_secret_expires_at: string|null, last_delivery_at: string|null, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookSubscription $subscription */
        $subscription = $this->resource;

        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'url' => $subscription->url,
            'events' => $subscription->events,
            'api_version' => $subscription->api_version,
            'is_active' => $subscription->is_active,
            'disabled_at' => $subscription->disabled_at?->toIso8601ZuluString(),
            'disabled_reason' => $subscription->disabled_reason,
            'consecutive_failures' => $subscription->consecutive_failures,
            'previous_secret_expires_at' => $subscription->previous_secret_expires_at?->toIso8601ZuluString(),
            'last_delivery_at' => $subscription->last_delivery_at?->toIso8601ZuluString(),
            'created_at' => $subscription->created_at->toIso8601ZuluString(),
            'updated_at' => $subscription->updated_at->toIso8601ZuluString(),
        ];
    }
}

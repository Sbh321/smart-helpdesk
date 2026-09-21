<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Models\WebhookSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The response to creating a subscription or rotating its secret: the subscription plus `secret`,
 * which is shown this one time and cannot be read again.
 *
 * @mixin WebhookSubscription
 */
final class WebhookSubscriptionWithSecretResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, url: string, events: list<string>, api_version: string, is_active: bool, disabled_at: string|null, disabled_reason: string|null, consecutive_failures: int, previous_secret_expires_at: string|null, last_delivery_at: string|null, created_at: string, updated_at: string, secret: string}
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookSubscription $subscription */
        $subscription = $this->resource;

        return [
            ...(new WebhookSubscriptionResource($subscription))->toArray($request),
            'secret' => $subscription->secret,
        ];
    }
}

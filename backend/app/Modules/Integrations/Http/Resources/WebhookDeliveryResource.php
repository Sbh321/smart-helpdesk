<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the delivery log. The payload is only in the single-delivery view.
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array{id: string, subscription_id: string, event_id: string, event_type: string, state: DeliveryState, attempt: int, manual_retries: int, next_attempt_at: string|null, last_attempted_at: string|null, response_status: int|null, response_excerpt: string|null, error: string|null, duration_ms: int|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => $delivery->id,
            'subscription_id' => $delivery->subscription_id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'state' => $delivery->state,
            'attempt' => $delivery->attempt,
            'manual_retries' => $delivery->manual_retries,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601ZuluString(),
            'last_attempted_at' => $delivery->last_attempted_at?->toIso8601ZuluString(),
            'response_status' => $delivery->response_status,
            'response_excerpt' => $delivery->response_excerpt,
            'error' => $delivery->error,
            'duration_ms' => $delivery->duration_ms,
            'created_at' => $delivery->created_at->toIso8601ZuluString(),
        ];
    }
}

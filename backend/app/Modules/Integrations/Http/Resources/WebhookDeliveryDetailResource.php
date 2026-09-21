<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery with the exact envelope that is (or was) sent.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryDetailResource extends JsonResource
{
    /**
     * @return array{id: string, subscription_id: string, event_id: string, event_type: string, state: DeliveryState, attempt: int, manual_retries: int, next_attempt_at: string|null, last_attempted_at: string|null, response_status: int|null, response_excerpt: string|null, error: string|null, duration_ms: int|null, created_at: string, payload: array<string, mixed>}
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            ...(new WebhookDeliveryResource($delivery))->toArray($request),
            'payload' => $delivery->payload,
        ];
    }
}

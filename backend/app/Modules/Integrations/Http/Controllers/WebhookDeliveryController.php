<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Integrations\Actions\RetryWebhookDelivery;
use App\Modules\Integrations\Http\Resources\WebhookDeliveryDetailResource;
use App\Modules\Integrations\Http\Resources\WebhookDeliveryResource;
use App\Modules\Integrations\Models\WebhookDelivery;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Single deliveries of the webhook log (docs/07-api/webhooks.md §Delivery).
 */
#[Group('Webhooks')]
final class WebhookDeliveryController
{
    /**
     * One delivery with the envelope that is sent.
     */
    public function show(WebhookDelivery $delivery): WebhookDeliveryDetailResource
    {
        return new WebhookDeliveryDetailResource($delivery);
    }

    /**
     * Retry a failed or dead delivery now, with a fresh retry schedule (at most five manual retries).
     */
    #[Response(status: 202, type: WebhookDeliveryResource::class)]
    public function retry(WebhookDelivery $delivery, RetryWebhookDelivery $retry): JsonResponse
    {
        return (new WebhookDeliveryResource($retry($delivery)))->response()->setStatusCode(202);
    }
}

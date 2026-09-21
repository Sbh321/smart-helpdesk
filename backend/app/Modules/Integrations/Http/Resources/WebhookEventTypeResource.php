<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event of the catalogue a subscription can listen to.
 *
 * @mixin WebhookEventType
 */
final class WebhookEventTypeResource extends JsonResource
{
    /**
     * @return array{type: WebhookEventType, description: string}
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookEventType $type */
        $type = $this->resource;

        return ['type' => $type, 'description' => $type->description()];
    }
}

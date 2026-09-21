<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event sent to one subscription, with the outcome of its latest attempt
 * (docs/07-api/webhooks.md §Delivery). The id is the `X-Helpdesk-Delivery-Id` header.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property DeliveryState $state
 * @property int $attempt
 * @property int $sequence_attempt
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property int|null $response_status
 * @property string|null $response_excerpt
 * @property string|null $error
 * @property int|null $duration_ms
 * @property int $manual_retries
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read WebhookSubscription $subscription
 */
#[UseFactory(WebhookDeliveryFactory::class)]
final class WebhookDelivery extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'state' => DeliveryState::class,
            'attempt' => 'integer',
            'sequence_attempt' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'manual_retries' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WebhookSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}

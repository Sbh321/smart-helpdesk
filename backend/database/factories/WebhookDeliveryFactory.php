<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookSubscription;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookDelivery> */
final class WebhookDeliveryFactory extends Factory
{
    use ForTenant;

    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'subscription_id' => fn (array $attributes): ?string => ($tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey()) === null
                ? null : WebhookSubscription::factory()->state(['tenant_id' => $tenantId])->create()->id,
            'event_id' => (string) Str::uuid7(),
            'event_type' => 'ticket.created',
            'payload' => fn (array $attributes): array => [
                'id' => $attributes['event_id'],
                'type' => $attributes['event_type'],
                'api_version' => 'v1',
                'tenant_id' => $attributes['tenant_id'] ?? tenant()?->getTenantKey(),
                'occurred_at' => now()->toIso8601ZuluString(),
                'data' => ['ticket' => ['id' => (string) Str::uuid7()]],
            ],
            'state' => DeliveryState::Pending,
            'attempt' => 0,
            'sequence_attempt' => 0,
        ];
    }

    public function forSubscription(WebhookSubscription $subscription): static
    {
        return $this->state(['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id]);
    }

    public function inState(DeliveryState $state): static
    {
        return $this->state(['state' => $state]);
    }
}

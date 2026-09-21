<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Support\Time\Clock;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fans one domain event out to the workspace's active subscriptions that listen to it: one
 * `webhook_deliveries` row per subscription (state `pending`), then one `DeliverWebhook` job each,
 * dispatched after the surrounding transaction commits (docs/07-api/webhooks.md §Delivery).
 *
 * The event id is shared by every delivery of the event and kept across retries, so receivers can
 * deduplicate on `X-Helpdesk-Event-Id`.
 */
final readonly class QueueWebhookEvent
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, mixed>|Closure(): (array<string, mixed>|null)  $data  built only when someone listens
     * @return Collection<int, WebhookDelivery>
     */
    public function __invoke(WebhookEventType $type, array|Closure $data, ?WebhookSubscription $only = null): Collection
    {
        $subscriptions = $only !== null
            ? collect([$only])
            : WebhookSubscription::query()->listeningTo($type->value)->get();
        if ($subscriptions->isEmpty()) {
            return collect();
        }

        $data = $data instanceof Closure ? $data() : $data;
        if ($data === null) {
            return collect();
        }

        $now = $this->clock->now();
        $eventId = (string) Str::uuid7();
        $envelope = $this->limit([
            'id' => $eventId,
            'type' => $type->value,
            'api_version' => 'v1',
            'tenant_id' => (string) tenant()?->getTenantKey(),
            'occurred_at' => $now->toIso8601ZuluString(),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($subscriptions, $type, $eventId, $envelope, $now): Collection {
            $deliveries = $subscriptions->map(fn (WebhookSubscription $subscription): WebhookDelivery => WebhookDelivery::query()->create([
                'subscription_id' => $subscription->id,
                'event_id' => $eventId,
                'event_type' => $type->value,
                'payload' => $envelope,
                'state' => DeliveryState::Pending,
                'next_attempt_at' => $now,
            ]));

            foreach ($deliveries as $delivery) {
                DeliverWebhook::dispatch($delivery->id, $delivery->subscription_id)->afterCommit();
            }

            return $deliveries->values();
        });
    }

    /**
     * Payloads above the size limit keep only the ids of their resources, with `data.truncated`.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function limit(array $envelope): array
    {
        $max = (int) config('helpdesk.webhooks.max_payload_bytes', 262144);
        if (strlen((string) json_encode($envelope)) <= $max) {
            return $envelope;
        }

        $trimmed = ['truncated' => true];
        foreach ((array) $envelope['data'] as $key => $value) {
            $trimmed[$key] = is_array($value) && isset($value['id']) ? ['id' => $value['id']] : (is_array($value) ? null : $value);
        }
        $envelope['data'] = $trimmed;

        return $envelope;
    }
}

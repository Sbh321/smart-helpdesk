<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Exceptions\DeliveryNotRetryable;
use App\Modules\Integrations\Jobs\DeliverWebhook;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Manual retry of a `failed` or `dead` delivery (docs/07-api/webhooks.md §Delivery): a new attempt
 * sequence starts now with the full retry schedule, the lifetime attempt counter continues, and a
 * delivery may be retried by hand at most five times. The subscription must be active.
 */
final readonly class RetryWebhookDelivery
{
    public function __construct(private Clock $clock) {}

    public function __invoke(WebhookDelivery $delivery): WebhookDelivery
    {
        return DB::transaction(function () use ($delivery): WebhookDelivery {
            /** @var WebhookDelivery $locked */
            $locked = WebhookDelivery::query()->with('subscription')->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->state, [DeliveryState::Failed, DeliveryState::Dead], true)) {
                throw DeliveryNotRetryable::because('state');
            }
            if (! $locked->subscription->is_active) {
                throw DeliveryNotRetryable::because('subscription_disabled');
            }
            if ($locked->manual_retries >= (int) config('helpdesk.webhooks.max_manual_retries', 5)) {
                throw DeliveryNotRetryable::because('manual_retries_exhausted');
            }

            $locked->forceFill([
                'state' => DeliveryState::Pending,
                'sequence_attempt' => 0,
                'manual_retries' => $locked->manual_retries + 1,
                'next_attempt_at' => $this->clock->now(),
            ])->save();

            DeliverWebhook::dispatch($locked->id, $locked->subscription_id)->afterCommit();

            return $locked;
        });
    }
}

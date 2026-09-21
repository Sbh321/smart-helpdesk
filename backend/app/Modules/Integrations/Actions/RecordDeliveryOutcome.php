<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Domain\Webhooks\RetrySchedule;
use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Modules\Integrations\Webhooks\DeliveryAttempt;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Writes the outcome of an attempt (docs/07-api/webhooks.md §Delivery):
 *
 * - success: `succeeded`, the subscription's `consecutive_failures` back to 0;
 * - failure: `failed` with `next_attempt_at` from the {@see RetrySchedule}, or `dead` after the last
 *   retry (a test `ping` is never retried automatically); the subscription's
 *   `consecutive_failures` goes up by one, and at the configured limit (20) the subscription is
 *   disabled with reason `consecutive_failures`.
 */
final readonly class RecordDeliveryOutcome
{
    public function __construct(
        private Clock $clock,
        private RetrySchedule $schedule,
        private SetWebhookActive $setActive,
    ) {}

    public function __invoke(WebhookDelivery $delivery, DeliveryAttempt $attempt): void
    {
        $now = $this->clock->now();

        DB::transaction(function () use ($delivery, $attempt, $now): void {
            $sequenceAttempt = $delivery->sequence_attempt + 1;
            $next = $attempt->succeeded || $delivery->event_type === WebhookEventType::Ping->value
                ? null
                : $this->schedule->nextAttemptAt($sequenceAttempt, $now);

            $delivery->forceFill([
                'state' => match (true) {
                    $attempt->succeeded => DeliveryState::Succeeded,
                    $next !== null => DeliveryState::Failed,
                    default => DeliveryState::Dead,
                },
                'attempt' => $delivery->attempt + 1,
                'sequence_attempt' => $sequenceAttempt,
                'next_attempt_at' => $next,
                'last_attempted_at' => $now,
                'response_status' => $attempt->status,
                'response_excerpt' => $attempt->excerpt,
                'error' => $attempt->error,
                'duration_ms' => $attempt->durationMs,
            ])->save();

            /** @var WebhookSubscription $subscription */
            $subscription = WebhookSubscription::query()->whereKey($delivery->subscription_id)->lockForUpdate()->firstOrFail();

            if ($attempt->succeeded) {
                $subscription->forceFill(['consecutive_failures' => 0, 'last_delivery_at' => $now])->save();

                return;
            }

            $subscription->forceFill([
                'consecutive_failures' => $subscription->consecutive_failures + 1,
                'last_delivery_at' => $now,
            ])->save();

            // MVP-SHORTCUT: auto-disable is audited and shown in Settings → Webhooks, but no notification
            // reaches the workspace admins; V1: V1-DP-06.
            if ($subscription->is_active && $subscription->consecutive_failures >= (int) config('helpdesk.webhooks.auto_disable_after', 20)) {
                ($this->setActive)($subscription, false, WebhookSubscription::DISABLED_FAILURES);
            }
        });
    }

    /** A delivery that will not be attempted (its subscription was disabled meanwhile). */
    public function abandon(WebhookDelivery $delivery, string $error): void
    {
        $delivery->forceFill([
            'state' => DeliveryState::Dead,
            'next_attempt_at' => null,
            'error' => $error,
        ])->save();
    }
}

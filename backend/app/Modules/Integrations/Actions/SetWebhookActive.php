<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Support\Time\Clock;

/**
 * Enables or disables a subscription (docs/07-api/webhooks.md §Subscription model). Disabling is
 * manual (`POST /webhooks/{webhook}/disable`) or automatic after too many consecutive failures, in
 * which case the audit entry's actor is `system`. Enabling clears the failure counter. Setting the
 * state it already has is a no-op without an audit entry.
 */
final readonly class SetWebhookActive
{
    public function __construct(private Clock $clock) {}

    public function __invoke(WebhookSubscription $subscription, bool $active, string $reason = WebhookSubscription::DISABLED_MANUALLY): WebhookSubscription
    {
        if ($subscription->is_active === $active) {
            return $subscription;
        }

        $subscription->forceFill($active
            ? ['is_active' => true, 'disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0]
            : ['is_active' => false, 'disabled_at' => $this->clock->now(), 'disabled_reason' => $reason],
        )->save();

        $automatic = ! $active && $reason === WebhookSubscription::DISABLED_FAILURES;
        Audit::record(
            $active ? 'webhook.enabled' : 'webhook.disabled',
            $subscription,
            $active ? [] : ['reason' => $reason, 'consecutive_failures' => $subscription->consecutive_failures],
            actorType: $automatic ? ActorType::System : null,
        );

        return $subscription;
    }
}

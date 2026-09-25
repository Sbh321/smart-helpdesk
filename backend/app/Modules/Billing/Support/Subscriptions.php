<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Subscription;
use App\Support\Time\Clock;

/** A workspace's subscription status now: the one entry point for the API, the middleware and jobs. */
final readonly class Subscriptions
{
    public function __construct(private Clock $clock, private BillingSettings $settings) {}

    public function statusOf(string $tenantId): SubscriptionStatus
    {
        return $this->status(Subscription::forTenant($tenantId));
    }

    public function status(?Subscription $subscription): SubscriptionStatus
    {
        return SubscriptionStatus::of($subscription, $this->clock->now(), $this->settings->graceDays());
    }
}

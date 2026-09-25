<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;

/**
 * Puts a new workspace on a plan (ADR-0025 §1, §8): the active trial plan unless another is given, for
 * its trial days, or a paid plan for `periods` periods. Idempotent: a workspace that already has a
 * subscription keeps it. Without an active trial plan and no plan given, the workspace stays unmanaged.
 */
final readonly class StartSubscription
{
    public function __construct(private Clock $clock) {}

    public function __invoke(Tenant $tenant, ?Plan $plan = null, int $periods = 1): ?Subscription
    {
        $existing = Subscription::forTenant((string) $tenant->getKey());
        if ($existing !== null) {
            return $existing;
        }

        $plan ??= Plan::activeTrial();
        if ($plan === null) {
            return null;
        }

        $now = $this->clock->now();
        $endsAt = $plan->isTrial()
            ? $now->addDays((int) $plan->trial_days)
            : $now->addMonthsNoOverflow(max(1, $periods) * (int) $plan->period_months);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->id,
            'ends_at' => $endsAt,
            'reminders' => [],
        ]);
        $subscription->setRelation('plan', $plan);

        Audit::record('subscription.started', $subscription, [
            'plan' => $plan->code,
            'ends_at' => $endsAt->toIso8601String(),
        ], tenantId: null);

        return $subscription;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A platform admin sets a workspace's plan and end date directly (a courtesy extension, a correction,
 * moving a trial to a paid plan). Reminders start again for the new end date. Audited with both sides.
 */
final class ChangeSubscription
{
    public function __invoke(Tenant $tenant, Plan $plan, CarbonImmutable $endsAt): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $endsAt): Subscription {
            $subscription = Subscription::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->first();
            $before = $subscription === null ? null : [
                'plan' => $subscription->plan->code,
                'ends_at' => $subscription->ends_at->toIso8601String(),
            ];

            $subscription ??= new Subscription(['tenant_id' => $tenant->getKey()]);
            $subscription->forceFill(['plan_id' => $plan->id, 'ends_at' => $endsAt, 'reminders' => []])->save();
            $subscription->setRelation('plan', $plan);

            Audit::record('subscription.changed', $subscription, [
                'before' => $before,
                'after' => ['plan' => $plan->code, 'ends_at' => $endsAt->toIso8601String()],
            ], tenantId: null);

            return $subscription;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ChangeSubscription;
use App\Modules\Billing\Http\Requests\ChangeSubscriptionRequest;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\Subscriptions;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;

/** A workspace's subscription for platform super admins (ADR-0025 §2). */
#[Group('Platform: billing')]
final class PlatformSubscriptionController
{
    /** Show a workspace's subscription. */
    public function show(Tenant $tenant, Subscriptions $subscriptions): SubscriptionResource
    {
        return new SubscriptionResource($subscriptions->statusOf((string) $tenant->getKey()));
    }

    /** Set a workspace's plan and end date. */
    public function update(ChangeSubscriptionRequest $request, Tenant $tenant, ChangeSubscription $change, Subscriptions $subscriptions): SubscriptionResource
    {
        $subscription = $change(
            $tenant,
            Plan::query()->findOrFail($request->validated('plan_id')),
            CarbonImmutable::parse((string) $request->validated('ends_at')),
        );

        return new SubscriptionResource($subscriptions->status($subscription));
    }
}

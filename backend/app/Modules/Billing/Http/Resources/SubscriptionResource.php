<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Support\SubscriptionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace's subscription as its state reads now (ADR-0025 §2). `state` is `none` for a workspace
 * billing does not manage, with every other field null.
 *
 * @mixin SubscriptionStatus
 */
final class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SubscriptionStatus $status */
        $status = $this->resource;

        return [
            /** @var 'trialing'|'active'|'grace'|'expired'|'none' */
            'state' => $status->state->value,
            'plan' => $status->plan === null ? null : new PlanResource($status->plan),
            'ends_at' => $status->endsAt?->toIso8601String(),
            'grace_ends_at' => $status->graceEndsAt?->toIso8601String(),
            /** Whole days until the next change of state; null when expired or unmanaged. */
            'days_left' => $status->daysLeft(),
            'read_only' => $status->readOnly(),
        ];
    }
}

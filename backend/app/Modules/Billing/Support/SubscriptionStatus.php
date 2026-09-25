<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Enums\PlanKind;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * A subscription's state at a moment (ADR-0025 §2), derived from its plan and `ends_at`; never stored,
 * so it is right whenever it is read. `SubscriptionStateSql` is its twin for lists and counts; the
 * boundaries are the same: before `ends_at` it runs, from `ends_at` until `ends_at + grace` it is in
 * grace, and from then on it has expired.
 */
final readonly class SubscriptionStatus
{
    private function __construct(
        public SubscriptionState $state,
        public ?Plan $plan,
        public ?CarbonImmutable $endsAt,
        public ?CarbonImmutable $graceEndsAt,
        private CarbonImmutable $now,
    ) {}

    public static function of(?Subscription $subscription, CarbonImmutable $now, int $graceDays): self
    {
        if ($subscription === null) {
            return new self(SubscriptionState::None, null, null, null, $now);
        }

        $endsAt = $subscription->ends_at;
        $graceEndsAt = $endsAt->addDays($graceDays);
        $plan = $subscription->plan;
        $state = match (true) {
            $now->lessThan($endsAt) => $plan->kind === PlanKind::Trial ? SubscriptionState::Trialing : SubscriptionState::Active,
            $now->lessThan($graceEndsAt) => SubscriptionState::Grace,
            default => SubscriptionState::Expired,
        };

        return new self($state, $plan, $endsAt, $graceEndsAt, $now);
    }

    public function readOnly(): bool
    {
        return $this->state->isReadOnly();
    }

    /**
     * Whole days left before the next change of state, rounded up (a trial ending in 30 hours has 2):
     * until `ends_at` while it runs, until the end of grace in grace; null when expired or unmanaged.
     */
    public function daysLeft(): ?int
    {
        $until = match ($this->state) {
            SubscriptionState::Trialing, SubscriptionState::Active => $this->endsAt,
            SubscriptionState::Grace => $this->graceEndsAt,
            default => null,
        };

        return $until === null ? null : (int) ceil($this->now->diffInSeconds($until) / 86400);
    }
}

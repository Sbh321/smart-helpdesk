<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * A subscription's state, derived from its plan and `ends_at` at a moment (ADR-0025 §2); never stored.
 * `none` is a workspace billing does not manage (no subscription row): it is treated as active.
 */
enum SubscriptionState: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case None = 'none';

    /** Writes are refused only after the grace period (ADR-0025 §6). */
    public function isReadOnly(): bool
    {
        return $this === self::Expired;
    }
}

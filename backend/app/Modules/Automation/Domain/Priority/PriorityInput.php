<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Priority;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;

/**
 * What the priority strategy may look at.
 *
 * `hoursWaited` is computed by the caller: hours since creation, measured on the tenant's default
 * business calendar when it is not 24×7 (ADR-0020), with "now" taken from the Clock.
 */
final readonly class PriorityInput
{
    public function __construct(
        public int $impact,
        public int $urgency,
        public CustomerTier $tier,
        public float $hoursWaited = 0.0,
    ) {
        foreach (['impact' => $impact, 'urgency' => $urgency] as $name => $value) {
            if ($value < 1 || $value > 4) {
                throw new InvalidStrategySettings("{$name} must be between 1 and 4, got {$value}.");
            }
        }

        if ($hoursWaited < 0) {
            throw new InvalidStrategySettings('hoursWaited must not be negative.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Exceptions;

use App\Modules\Sla\Domain\Timer\TimerState;
use DomainException;

/**
 * An SLA timer operation is not allowed in the timer's current state.
 */
final class InvalidTimerTransition extends DomainException
{
    public static function between(TimerState $from, TimerState $to): self
    {
        return new self("An SLA timer cannot move from {$from->value} to {$to->value}.");
    }

    public static function operation(string $operation, TimerState $state): self
    {
        return new self("An SLA timer in state {$state->value} cannot be {$operation}.");
    }
}

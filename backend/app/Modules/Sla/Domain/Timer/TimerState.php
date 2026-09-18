<?php

declare(strict_types=1);

namespace App\Modules\Sla\Domain\Timer;

/**
 * SLA timer states and the allowed transitions (docs/05-algorithms/sla-evaluation.md §States).
 *
 * Beyond the diagram: a paused timer can be met (the ticket is resolved straight from pending), and every
 * unfinished timer can be cancelled (ticket closed as a duplicate, docs/04-domain/sla.md).
 */
enum TimerState: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Warning = 'warning';
    case Breached = 'breached';
    case Met = 'met';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Running => [self::Paused, self::Warning, self::Breached, self::Met, self::Cancelled],
            self::Paused => [self::Running, self::Warning, self::Met, self::Cancelled],
            self::Warning => [self::Paused, self::Breached, self::Met, self::Cancelled],
            self::Breached => [self::Met, self::Cancelled],
            self::Met, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Met and cancelled timers never change again. */
    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** States the minute check looks at. */
    public function isCounting(): bool
    {
        return $this === self::Running || $this === self::Warning;
    }
}

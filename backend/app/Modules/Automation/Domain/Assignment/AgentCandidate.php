<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use DateTimeInterface;

/**
 * An agent as seen by the assignment strategy. The loader computes `onShift` from the agent's shifts
 * at the Clock's "now" and `openTickets` from tickets in status assigned, in_progress or pending.
 */
final readonly class AgentCandidate
{
    /**
     * @param  list<string>  $skills
     * @param  list<string>  $teamIds
     */
    public function __construct(
        public string $id,
        public int $openTickets,
        public int $capacity,
        public array $skills = [],
        public array $teamIds = [],
        public bool $active = true,
        public bool $available = true,
        public bool $onShift = true,
        public ?DateTimeInterface $lastAssignedAt = null,
    ) {
        if ($openTickets < 0 || $capacity < 0) {
            throw new InvalidStrategySettings('Open tickets and capacity must not be negative.');
        }
    }

    /**
     * open tickets ÷ capacity; an agent without capacity counts as full.
     */
    public function load(): float
    {
        return $this->capacity === 0 ? 1.0 : $this->openTickets / $this->capacity;
    }

    /**
     * Exact comparison of two loads (cross-multiplication, no floating-point rounding).
     */
    public function compareLoad(self $other): int
    {
        return ($this->openTickets * $other->capacity) <=> ($other->openTickets * $this->capacity);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Agents\Support;

/**
 * `Contracts\DirectoryUsage::workload()` as a typed value, so the API resource has a shape Scramble can read.
 */
final readonly class AgentWorkloadView
{
    /**
     * @param  array{P1: int, P2: int, P3: int, P4: int}  $byPriority
     */
    public function __construct(
        public int $activeTicketCount,
        public int $capacity,
        public float $load,
        public array $byPriority,
    ) {}

    /**
     * @param  array{active_ticket_count: int, capacity: int, load: float, by_priority: array{P1: int, P2: int, P3: int, P4: int}}  $workload
     */
    public static function fromArray(array $workload): self
    {
        return new self(
            (int) $workload['active_ticket_count'],
            (int) $workload['capacity'],
            (float) $workload['load'],
            [
                'P1' => (int) $workload['by_priority']['P1'],
                'P2' => (int) $workload['by_priority']['P2'],
                'P3' => (int) $workload['by_priority']['P3'],
                'P4' => (int) $workload['by_priority']['P4'],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Policies;

use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Support\Experiments\SeededRandom;

/**
 * E1 comparison policy: a uniformly random agent among those with the required skills (capacity ignored).
 * Seeded, so a run can be repeated; experiment code only, never bound as the assignment strategy.
 */
final readonly class RandomPolicy implements AssignmentStrategy
{
    public const string NAME = 'random';

    public function __construct(private SeededRandom $random) {}

    public function choose(TicketNeeds $ticket, array $candidates): AssignmentResult
    {
        $skilled = SkilledAgents::of($ticket, $candidates);
        $chosen = $skilled === [] ? null : $this->random->pick($skilled);

        return new AssignmentResult($chosen?->id, $chosen === null ? [] : [$chosen], [], self::NAME, '1.0.0');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Policies;

use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AssignmentResult;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;

/**
 * E1 comparison policy: agents take turns in id order; the next agent after the last one chosen
 * that has the required skills gets the ticket (capacity ignored). Remembers its position, so one
 * instance serves one replay; experiment code only, never bound as the assignment strategy.
 */
final class RoundRobinPolicy implements AssignmentStrategy
{
    public const string NAME = 'round_robin';

    private ?string $last = null;

    public function choose(TicketNeeds $ticket, array $candidates): AssignmentResult
    {
        $skilled = SkilledAgents::of($ticket, $candidates);
        if ($skilled === []) {
            return new AssignmentResult(null, [], [], self::NAME, '1.0.0');
        }

        // The first skilled agent after the last one chosen, wrapping around to the start.
        $chosen = $skilled[0];
        foreach ($skilled as $agent) {
            if ($this->last !== null && strcmp($agent->id, $this->last) > 0) {
                $chosen = $agent;
                break;
            }
        }
        $this->last = $chosen->id;

        return new AssignmentResult($chosen->id, [$chosen], [], self::NAME, '1.0.0');
    }
}

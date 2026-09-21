<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Console\Experiments\Datasets\Workload;
use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use Carbon\CarbonImmutable;

/**
 * Replays the workload through one assignment policy (evaluation-methodology.md §4, E1).
 *
 * Tickets arrive in order; before each arrival every ticket whose handling time is over is resolved,
 * so load rises and falls. The policy sees each agent's open tickets and last assignment time, exactly
 * like the live loader provides them. After each assignment every agent's open tickets are recorded.
 * A ticket nobody can take stays unassigned (it is counted, not queued).
 */
final class AssignmentReplay
{
    /** Simulated time starts here; only differences matter. */
    private const string START = '2026-09-01 09:00:00';

    /**
     * @return array{snapshots: list<array{ticket: string, time_s: int, open: array<string, int>}>, overflows: int, unassigned: int, assigned: array<string, int>}
     */
    public function run(AssignmentStrategy $policy, Workload $workload): array
    {
        $start = CarbonImmutable::parse(self::START, 'UTC');
        $capacity = array_column($workload->agents, 'capacity', 'id');
        $skills = array_column($workload->agents, 'skills', 'id');
        $open = array_fill_keys(array_keys($capacity), 0);
        $assigned = $open;
        $lastAssigned = [];
        $resolveAt = [];        // list of [time, agent]
        $snapshots = [];
        $overflows = 0;
        $unassigned = 0;

        foreach ($workload->tickets as $ticket) {
            $now = $ticket['arrival_s'];

            // Resolve everything finished by now.
            foreach ($resolveAt as $key => [$time, $agent]) {
                if ($time <= $now) {
                    $open[$agent]--;
                    unset($resolveAt[$key]);
                }
            }

            $candidates = [];
            foreach ($capacity as $id => $cap) {
                $candidates[] = new AgentCandidate(
                    id: $id,
                    openTickets: $open[$id],
                    capacity: $cap,
                    skills: $skills[$id],
                    lastAssignedAt: isset($lastAssigned[$id]) ? $start->addSeconds($lastAssigned[$id]) : null,
                );
            }

            $result = $policy->choose(new TicketNeeds($ticket['id'], $workload->categorySkills[$ticket['category']]), $candidates);
            $agent = $result->agentId;

            if ($agent === null) {
                $unassigned++;
            } else {
                $open[$agent]++;
                $assigned[$agent]++;
                $lastAssigned[$agent] = $now;
                $resolveAt[] = [$now + $ticket['handling_s'], $agent];
                if ($open[$agent] > $capacity[$agent]) {
                    $overflows++;
                }
            }

            $snapshots[] = ['ticket' => $ticket['id'], 'time_s' => $now, 'open' => $open];
        }

        return ['snapshots' => $snapshots, 'overflows' => $overflows, 'unassigned' => $unassigned, 'assigned' => $assigned];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console\Experiments;

use App\Support\Experiments\SeededRandom;
use Carbon\CarbonImmutable;

/**
 * The generated ticket histories of E6: every ticket is created at a random moment of the last
 * `$days` days and then walks along the lifecycle (routed to a team, worked on, pending, resolved,
 * sometimes reopened or closed), with occasional priority changes and title edits. Pure and seeded;
 * the result is one list of steps in time order across all tickets.
 */
final class HistoryPlan
{
    private const array NEXT = [
        'open' => ['assigned'],
        'assigned' => ['in_progress'],
        'in_progress' => ['pending', 'resolved'],
        'pending' => ['in_progress'],
        'resolved' => ['closed', 'reopened'],
        'closed' => [],
    ];

    /**
     * Each step names the ticket (its index), the instant, the ticket event type, the ticket columns
     * to change (`set`) and the event's old and new values.
     *
     * @param  list<string>  $teams  team ids
     * @return list<array{ticket: int, at: CarbonImmutable, type: string, set: array<string, string|null>, old: array<string, string|null>, new: array<string, string|null>}>
     */
    public function steps(SeededRandom $random, int $tickets, int $days, CarbonImmutable $end, array $teams): array
    {
        $steps = [];
        for ($ticket = 0; $ticket < $tickets; $ticket++) {
            $at = $end->subSeconds($random->int(3600, $days * 86400));
            $status = 'open';
            $team = null;
            $priority = $random->pick(['P1', 'P2', 'P3', 'P4']);
            $steps[] = $this->step($ticket, $at, 'created', ['status' => 'open', 'priority_level' => $priority], [], ['status' => 'open', 'priority' => $priority]);

            for ($n = 0; $n < 10 && self::NEXT[$status] !== []; $n++) {
                $at = $at->addSeconds($random->int(600, 40 * 3600));
                if ($at >= $end) {
                    break;
                }

                // Now and then a priority change or a title edit instead of a lifecycle move.
                if ($random->chance(0.15)) {
                    $new = $random->pick(array_values(array_diff(['P1', 'P2', 'P3', 'P4'], [$priority])));
                    $steps[] = $this->step($ticket, $at, 'priority_changed', ['priority_level' => $new], ['priority' => $priority], ['priority' => $new]);
                    $priority = $new;

                    continue;
                }
                if ($random->chance(0.1)) {
                    $steps[] = $this->step($ticket, $at, 'edited', ['title' => "History ticket {$ticket} (edited at step {$n})"], [], []);

                    continue;
                }

                $to = $random->pick(self::NEXT[$status]);
                if ($to === 'assigned') {
                    $team = $random->pick($teams);
                    $steps[] = $this->step($ticket, $at, 'assigned', ['status' => 'assigned', 'team_id' => $team], ['status' => $status, 'team_id' => null, 'agent_id' => null], ['status' => 'assigned', 'team_id' => $team, 'agent_id' => null]);
                    $status = 'assigned';

                    continue;
                }

                $target = $to === 'reopened' ? 'in_progress' : $to;
                $set = ['status' => $target];
                $set += match ($target) {
                    'resolved' => ['resolved_at' => $at->toIso8601ZuluString('microsecond')],
                    'closed' => ['closed_at' => $at->toIso8601ZuluString('microsecond')],
                    default => $status === 'resolved' ? ['resolved_at' => null] : [],
                };
                $steps[] = $this->step($ticket, $at, $to === 'reopened' ? 'reopened' : 'status_changed', $set, ['status' => $status], ['status' => $target]);
                $status = $target;
            }
        }

        usort($steps, fn (array $a, array $b): int => $a['at'] <=> $b['at'] ?: $a['ticket'] <=> $b['ticket']);

        return $steps;
    }

    /**
     * @param  array<string, string|null>  $set
     * @param  array<string, string|null>  $old
     * @param  array<string, string|null>  $new
     * @return array{ticket: int, at: CarbonImmutable, type: string, set: array<string, string|null>, old: array<string, string|null>, new: array<string, string|null>}
     */
    private function step(int $ticket, CarbonImmutable $at, string $type, array $set, array $old, array $new): array
    {
        return ['ticket' => $ticket, 'at' => $at, 'type' => $type, 'set' => $set, 'old' => $old, 'new' => $new];
    }
}

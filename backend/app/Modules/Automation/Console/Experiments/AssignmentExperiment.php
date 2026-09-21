<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Console\Experiments\Datasets\Workload;
use App\Modules\Automation\Console\Experiments\Policies\RandomPolicy;
use App\Modules\Automation\Console\Experiments\Policies\RoundRobinPolicy;
use App\Modules\Automation\Contracts\AssignmentStrategy;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Support\Experiments\Experiment;
use App\Support\Experiments\ExperimentContext;
use App\Support\Experiments\Metrics;

/**
 * E1 — assignment fairness (evaluation-methodology.md §4): the workload replayed under random,
 * round robin and the strategy under test. Tables T1 and T2, plots 1–3.
 */
final class AssignmentExperiment implements Experiment
{
    public function key(): string
    {
        return 'e1';
    }

    public function title(): string
    {
        return 'Assignment fairness';
    }

    public function run(ExperimentContext $context): array
    {
        $workload = Workload::fromFolder(dirname($context->dataset('workload/tickets.csv')));
        $strategy = $context->strategy('assignment', AssignmentStrategy::class);
        $probe = $strategy->choose(new TicketNeeds('probe'), []);

        $policies = [
            RandomPolicy::NAME => new RandomPolicy($context->random()),
            RoundRobinPolicy::NAME => new RoundRobinPolicy,
            $probe->strategy => $strategy,
        ];

        $capacity = array_column($workload->agents, 'capacity', 'id');
        $summary = [];
        $perAgent = [];
        $series = [];

        foreach ($policies as $name => $policy) {
            $replay = (new AssignmentReplay)->run($policy, $workload);
            $measures = array_map(fn (array $snapshot): array => LoadMeasures::of($snapshot['open'], $capacity), $replay['snapshots']);
            $final = end($measures) ?: LoadMeasures::of([], []);
            $average = fn (string $key): float => Metrics::round(Metrics::mean(array_column($measures, $key)));

            $summary[] = [
                'policy' => $name,
                'tickets' => count($workload->tickets),
                'assigned' => array_sum($replay['assigned']),
                'unassigned' => $replay['unassigned'],
                'capacity_overflows' => $replay['overflows'],
                'final_std_open' => Metrics::round($final['std_open']),
                'final_max_open' => $final['max_open'],
                'final_min_open' => $final['min_open'],
                'final_jain_open' => Metrics::round($final['jain_open']),
                'final_jain_utilisation' => Metrics::round($final['jain_utilisation']),
                'avg_std_open' => $average('std_open'),
                'avg_max_open' => $average('max_open'),
                'avg_min_open' => $average('min_open'),
                'avg_jain_open' => $average('jain_open'),
                'avg_jain_utilisation' => $average('jain_utilisation'),
                'peak_utilisation' => Metrics::round(max(array_column($measures, 'max_utilisation'))),
            ];

            $lastOpen = end($replay['snapshots'])['open'] ?? [];
            foreach ($workload->agents as $agent) {
                $id = $agent['id'];
                $perAgent[$id] ??= ['agent' => $id, 'capacity' => $agent['capacity'], 'skills' => implode(' ', $agent['skills'])];
                $perAgent[$id]["{$name}_final_open"] = $lastOpen[$id] ?? 0;
                $perAgent[$id]["{$name}_avg_open"] = Metrics::round(Metrics::mean(array_map(fn (array $s): int => $s['open'][$id], $replay['snapshots'])), 2);
                $perAgent[$id]["{$name}_assigned"] = $replay['assigned'][$id];
            }

            foreach ($replay['snapshots'] as $index => $snapshot) {
                $series[] = [
                    'policy' => $name,
                    'ticket_index' => $index + 1,
                    'time_h' => Metrics::round($snapshot['time_s'] / 3600, 3),
                    'std_open' => Metrics::round($measures[$index]['std_open']),
                    'max_open' => $measures[$index]['max_open'],
                    'min_open' => $measures[$index]['min_open'],
                    'jain_utilisation' => Metrics::round($measures[$index]['jain_utilisation']),
                    'max_utilisation' => Metrics::round($measures[$index]['max_utilisation']),
                ];
            }
        }

        $context->output->csv('t1-assignment-policies.csv', $summary);
        $context->output->csv('t2-final-load-per-agent.csv', array_values($perAgent));
        $context->output->csv('load-over-time.csv', $series);
        $context->output->json('summary.json', ['policies' => $summary]);

        return [
            'strategy' => ['name' => $probe->strategy, 'version' => $probe->strategyVersion, 'class' => $strategy::class],
            'settings' => ['agents' => count($workload->agents), 'tickets' => count($workload->tickets), 'comparison_policies' => [RandomPolicy::NAME, RoundRobinPolicy::NAME]],
        ];
    }
}

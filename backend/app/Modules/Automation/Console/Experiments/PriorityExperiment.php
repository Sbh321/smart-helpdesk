<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Console\Experiments\Datasets\Workload;
use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\CustomerTier;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Support\Experiments\Experiment;
use App\Support\Experiments\ExperimentContext;
use App\Support\Experiments\Metrics;

/**
 * E3 — priority scoring behaviour (evaluation-methodology.md §4): the 12 scenarios, one-at-a-time
 * weight changes of ±0.1 on 200 generated tickets, and the ageing curve of one ticket over 0–96 h.
 * Tables T6 and T7, plots 7 and 8.
 */
final class PriorityExperiment implements Experiment
{
    public const int SENSITIVITY_TICKETS = 200;

    public const float STEP = 0.1;

    /** The ageing curve follows worked example S03 (impact 2, urgency 3, premium). */
    private const array AGEING_TICKET = ['impact' => 2, 'urgency' => 3, 'tier' => 'premium'];

    private const array LEVEL_RANK = ['P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4];

    public function key(): string
    {
        return 'e3';
    }

    public function title(): string
    {
        return 'Priority scoring behaviour';
    }

    public function run(ExperimentContext $context): array
    {
        $settings = PrioritySettings::fromArray((array) config('helpdesk.automation.priority.baseline'));
        $strategy = $this->strategy($context, $settings);

        // Scenario table (T6).
        $scenarios = [];
        foreach ($context->datasetJson('priority-scenarios.json')['scenarios'] as $scenario) {
            $result = $strategy->score(new PriorityInput($scenario['impact'], $scenario['urgency'], CustomerTier::from($scenario['tier']), (float) $scenario['waited_h']));
            $scenarios[] = [
                'id' => $scenario['id'],
                'title' => $scenario['title'],
                'impact' => $scenario['impact'],
                'urgency' => $scenario['urgency'],
                'tier' => $scenario['tier'],
                'waited_h' => $scenario['waited_h'],
                'expected_score' => (float) $scenario['expected_score'],
                'actual_score' => $result->score,
                'expected_level' => $scenario['expected_level'],
                'actual_level' => $result->level->value,
                'match' => abs($result->score - $scenario['expected_score']) < 0.05 && $result->level->value === $scenario['expected_level'],
            ];
        }

        // One-at-a-time sensitivity (T7).
        $tickets = array_map(
            fn (array $ticket): PriorityInput => new PriorityInput($ticket['impact'], $ticket['urgency'], CustomerTier::from($ticket['tier']), $ticket['waited_h']),
            array_slice(Workload::fromFolder(dirname($context->dataset('workload/tickets.csv')))->tickets, 0, self::SENSITIVITY_TICKETS),
        );
        $baseLevels = array_map(fn (PriorityInput $input): string => $strategy->score($input)->level->value, $tickets);
        $sensitivity = [];
        foreach (array_keys($settings->weights) as $part) {
            foreach ([-self::STEP, self::STEP] as $delta) {
                $weights = self::shifted($settings->weights, $part, $delta);
                $changed = new PrioritySettings($weights, $settings->thresholds, $settings->ageFullHours);
                $variant = $this->strategy($context, $changed);
                $up = $down = 0;
                foreach ($tickets as $index => $input) {
                    $rank = self::LEVEL_RANK[$variant->score($input)->level->value] <=> self::LEVEL_RANK[$baseLevels[$index]];
                    $up += $rank < 0 ? 1 : 0;
                    $down += $rank > 0 ? 1 : 0;
                }
                $sensitivity[] = [
                    'weight' => $part,
                    'delta' => $delta,
                    ...array_combine(array_map(fn (string $name): string => "w_{$name}", array_keys($weights)), array_map(fn (float $w): float => Metrics::round($w), $weights)),
                    'tickets' => count($tickets),
                    'changed' => $up + $down,
                    'changed_pct' => Metrics::round(100 * Metrics::ratio($up + $down, count($tickets)), 1),
                    'moved_up' => $up,
                    'moved_down' => $down,
                ];
            }
        }

        $levels = array_count_values($baseLevels);
        ksort($levels);

        // Ageing curve (plot 7).
        $curve = [];
        for ($hours = 0; $hours <= 96; $hours++) {
            $result = $strategy->score(new PriorityInput(self::AGEING_TICKET['impact'], self::AGEING_TICKET['urgency'], CustomerTier::from(self::AGEING_TICKET['tier']), (float) $hours));
            $curve[] = ['hours' => $hours, 'score' => $result->score, 'level' => $result->level->value];
        }
        $firstP2 = array_values(array_filter($curve, fn (array $point): bool => $point['level'] === 'P2'))[0]['hours'] ?? null;

        $context->output->csv('t6-priority-scenarios.csv', $scenarios);
        $context->output->csv('t7-priority-weight-sensitivity.csv', $sensitivity);
        $context->output->csv('ageing-curve.csv', $curve);
        $context->output->json('summary.json', [
            'scenarios' => count($scenarios),
            'scenarios_matching' => count(array_filter($scenarios, fn (array $row): bool => $row['match'])),
            'sensitivity_tickets' => count($tickets),
            'default_level_counts' => $levels,
            'most_sensitive' => collect($sensitivity)->sortByDesc('changed')->first(),
            'ageing_ticket' => self::AGEING_TICKET,
            'ageing_first_p2_hour' => $firstP2,
        ]);

        $probe = $strategy->score(new PriorityInput(1, 1, CustomerTier::Standard));

        return [
            'strategy' => ['name' => $probe->strategy, 'version' => $probe->strategyVersion, 'class' => $strategy::class],
            'settings' => ['default' => $settings->toArray(), 'step' => self::STEP, 'rescaling' => 'the other weights are multiplied by (1 − new) ÷ (1 − old) so the sum stays 1'],
        ];
    }

    /**
     * One weight moved by `$delta` (kept within 0–1), the others rescaled so the sum stays 1.
     *
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    public static function shifted(array $weights, string $part, float $delta): array
    {
        $old = $weights[$part];
        $new = max(0.0, min(1.0, $old + $delta));
        $factor = $old >= 1.0 ? 0.0 : (1.0 - $new) / (1.0 - $old);

        $shifted = [];
        foreach ($weights as $name => $weight) {
            $shifted[$name] = round($name === $part ? $new : $weight * $factor, 10);
        }

        return $shifted;
    }

    private function strategy(ExperimentContext $context, PrioritySettings $settings): PriorityStrategy
    {
        return $context->strategy('priority', PriorityStrategy::class, ['settings' => $settings]);
    }
}

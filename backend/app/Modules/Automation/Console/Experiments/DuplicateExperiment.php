<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Support\Experiments\Experiment;
use App\Support\Experiments\ExperimentContext;
use App\Support\Experiments\Metrics;
use App\Support\Experiments\SeededRandom;

/**
 * E2 — duplicate detection accuracy (evaluation-methodology.md §4). Every score comes from the
 * `DuplicateStrategy` contract: the strategy is built with a tiny threshold so it reports every
 * non-zero score, and the experiment applies the thresholds itself. Tables T3–T5, plots 4–6.
 */
final class DuplicateExperiment implements Experiment
{
    /** Low enough that every pair with a shared word is returned (the setting must be above 0). */
    private const float REPORT_ALL = 0.000001;

    private const array VARIANTS = ['title_description', 'title_only'];

    private const float TRAIN_SHARE = 0.7;

    private DuplicateStrategy $strategy;

    public function key(): string
    {
        return 'e2';
    }

    public function title(): string
    {
        return 'Duplicate detection accuracy';
    }

    public function run(ExperimentContext $context): array
    {
        $this->strategy = $context->strategy('duplicates', DuplicateStrategy::class, [
            'settings' => new DuplicateSettings(threshold: self::REPORT_ALL, maxSuggestions: 5),
        ]);
        $default = DuplicateSettings::fromArray((array) config('helpdesk.automation.duplicates.baseline'))->threshold;
        $pairs = $context->datasetJson('duplicates.json')['pairs'];
        $haystack = $context->datasetJson('duplicates-haystack.json')['tickets'];
        [$train, $test] = $this->split($pairs, $context->random());

        $scores = [];
        foreach (self::VARIANTS as $variant) {
            foreach ($pairs as $pair) {
                $scores[$variant][$pair['id']] = $this->score($variant, $pair);
            }
        }

        $thresholdRows = [];
        $heldOut = [];
        $comparison = [];
        foreach (self::VARIANTS as $variant) {
            foreach (self::thresholds() as $threshold) {
                $thresholdRows[] = ['variant' => $variant, 'threshold' => $threshold, ...$this->confusion($pairs, $scores[$variant], $threshold)];
            }

            $best = $this->bestThreshold($train, $scores[$variant]);
            foreach (['default' => $default, 'best_on_train' => $best] as $choice => $threshold) {
                $trainMeasures = $this->confusion($train, $scores[$variant], $threshold);
                $testMeasures = $this->confusion($test, $scores[$variant], $threshold);
                $heldOut[] = [
                    'variant' => $variant,
                    'threshold_choice' => $choice,
                    'threshold' => $threshold,
                    'train_f1' => $trainMeasures['f1'],
                    'test_pairs' => count($test),
                    ...array_combine(array_map(fn (string $key): string => "test_{$key}", array_keys($testMeasures)), $testMeasures),
                ];
            }

            $recall = $this->recallAtFive($variant, $pairs, $haystack, [$default, $best]);
            $byLabel = fn (string $label): float => Metrics::round(Metrics::mean(array_map(
                fn (array $pair): float => $scores[$variant][$pair['id']],
                array_values(array_filter($pairs, fn (array $pair): bool => $pair['label'] === $label)),
            )));
            $comparison[] = [
                'variant' => $variant,
                'mean_score_duplicates' => $byLabel('duplicate'),
                'mean_score_non_duplicates' => $byLabel('non_duplicate'),
                'best_threshold' => $best,
                'test_f1_at_best' => $this->confusion($test, $scores[$variant], $best)['f1'],
                'test_f1_at_default' => $this->confusion($test, $scores[$variant], $default)['f1'],
                'recall_at_5_ranking_only' => $recall['ranking'],
                'recall_at_5_at_default' => $recall[(string) $default],
                'recall_at_5_at_best' => $recall[(string) $best],
                'pool_size' => count($haystack) + count(array_filter($pairs, fn (array $pair): bool => $pair['label'] === 'duplicate')),
            ];
        }

        $pairRows = array_map(fn (array $pair): array => [
            'pair' => $pair['id'],
            'label' => $pair['label'],
            'source' => $pair['source'],
            'same_category' => $pair['category_a'] === $pair['category_b'],
            'split' => isset($test[$pair['id']]) ? 'test' : 'train',
            'score_title_description' => Metrics::round($scores['title_description'][$pair['id']]),
            'score_title_only' => Metrics::round($scores['title_only'][$pair['id']]),
        ], $pairs);

        $context->output->csv('t3-duplicate-thresholds.csv', $thresholdRows);
        $context->output->csv('t4-duplicate-held-out.csv', $heldOut);
        $context->output->csv('t5-duplicate-variants.csv', $comparison);
        $context->output->csv('pair-scores.csv', $pairRows);
        $context->output->json('summary.json', ['default_threshold' => $default, 'held_out' => $heldOut, 'variants' => $comparison]);

        $probe = $this->strategy->find(new TicketText('probe', 'probe'), []);

        return [
            'strategy' => ['name' => $probe->strategy, 'version' => $probe->strategyVersion, 'class' => $this->strategy::class],
            'settings' => [
                'default_threshold' => $default,
                'thresholds' => self::thresholds(),
                'train_share' => self::TRAIN_SHARE,
                'split' => 'stratified by label, seeded shuffle',
                'best_threshold_rule' => 'highest F1 on the training pairs; the lowest threshold wins a tie',
                'recall_at_5' => 'each duplicate pair: side a searches the haystack plus every side b; a hit when its own side b is among the five suggestions',
                'strategy_settings' => $probe->settings,
            ],
        ];
    }

    /** @return list<float> 0.10, 0.15, …, 0.90 */
    public static function thresholds(): array
    {
        return array_map(fn (int $k): float => $k / 100, range(10, 90, 5));
    }

    /**
     * @param  array{a: array{title: string, description: string}, b: array{title: string, description: string}}  $pair
     */
    private function score(string $variant, array $pair): float
    {
        $result = $this->strategy->find($this->text('a', $pair['a'], $variant), [$this->text('b', $pair['b'], $variant)]);

        return $result->matches === [] ? 0.0 : $result->matches[0]->score();
    }

    /**
     * @param  array{title: string, description: string}  $ticket
     */
    private function text(string $id, array $ticket, string $variant): TicketText
    {
        return new TicketText($id, $ticket['title'], $variant === 'title_only' ? '' : $ticket['description']);
    }

    /**
     * Confusion counts and measures of "suggest when score ≥ threshold" over some pairs.
     *
     * @param  array<array-key, array{id: string, label: string}>  $pairs
     * @param  array<string, float>  $scores
     * @return array{tp: int, fp: int, fn: int, tn: int, precision: float, recall: float, f1: float}
     */
    public static function confusionOf(array $pairs, array $scores, float $threshold): array
    {
        $tp = $fp = $fn = $tn = 0;
        foreach ($pairs as $pair) {
            $suggested = $scores[$pair['id']] >= $threshold;
            $duplicate = $pair['label'] === 'duplicate';
            match (true) {
                $suggested && $duplicate => $tp++,
                $suggested => $fp++,
                $duplicate => $fn++,
                default => $tn++,
            };
        }
        $precision = Metrics::precision($tp, $fp);
        $recall = Metrics::recall($tp, $fn);

        return [
            'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn,
            'precision' => Metrics::round($precision),
            'recall' => Metrics::round($recall),
            'f1' => Metrics::round(Metrics::f1($precision, $recall)),
        ];
    }

    /**
     * @param  array<array-key, array{id: string, label: string}>  $pairs
     * @param  array<string, float>  $scores
     * @return array{tp: int, fp: int, fn: int, tn: int, precision: float, recall: float, f1: float}
     */
    private function confusion(array $pairs, array $scores, float $threshold): array
    {
        return self::confusionOf($pairs, $scores, $threshold);
    }

    /**
     * @param  array<string, array{id: string, label: string}>  $train
     * @param  array<string, float>  $scores
     */
    private function bestThreshold(array $train, array $scores): float
    {
        $best = self::thresholds()[0];
        $bestF1 = -1.0;
        foreach (self::thresholds() as $threshold) {
            $f1 = $this->confusion($train, $scores, $threshold)['f1'];
            if ($f1 > $bestF1) {
                [$best, $bestF1] = [$threshold, $f1];
            }
        }

        return $best;
    }

    /**
     * Seeded split, stratified by label: 70 % of the duplicates and 70 % of the non-duplicates train.
     *
     * @param  list<array{id: string, label: string}>  $pairs
     * @return array{array<string, array{id: string, label: string}>, array<string, array{id: string, label: string}>}
     */
    private function split(array $pairs, SeededRandom $random): array
    {
        $train = [];
        $test = [];
        foreach (['duplicate', 'non_duplicate'] as $label) {
            $group = $random->shuffle(array_values(array_filter($pairs, fn (array $pair): bool => $pair['label'] === $label)));
            $cut = (int) round(count($group) * self::TRAIN_SHARE);
            foreach ($group as $index => $pair) {
                $index < $cut ? $train[$pair['id']] = $pair : $test[$pair['id']] = $pair;
            }
        }
        ksort($train);
        ksort($test);

        return [$train, $test];
    }

    /**
     * Recall@5: the share of duplicate pairs whose partner is among the five suggestions when side a
     * searches the haystack plus all side-b tickets. With a threshold, the partner must also score at
     * least that much (the five best at a higher threshold are the five best overall that pass it).
     *
     * @param  list<array{id: string, label: string, a: array{title: string, description: string}, b: array{title: string, description: string}}>  $pairs
     * @param  list<array{id: string, title: string, description: string}>  $haystack
     * @param  list<float>  $thresholds
     * @return array<string, float> `ranking` (no threshold) and one entry per threshold
     */
    private function recallAtFive(string $variant, array $pairs, array $haystack, array $thresholds): array
    {
        $duplicates = array_values(array_filter($pairs, fn (array $pair): bool => $pair['label'] === 'duplicate'));
        $pool = array_map(fn (array $ticket): TicketText => $this->text($ticket['id'], $ticket, $variant), $haystack);
        foreach ($duplicates as $pair) {
            $pool[] = $this->text("{$pair['id']}-b", $pair['b'], $variant);
        }

        $thresholds = array_values(array_unique($thresholds));
        $hits = ['ranking' => 0] + array_fill_keys(array_map(strval(...), $thresholds), 0);
        foreach ($duplicates as $pair) {
            $result = $this->strategy->find($this->text("{$pair['id']}-a", $pair['a'], $variant), $pool);
            foreach ($result->matches as $match) {
                if ($match->ticketId === "{$pair['id']}-b") {
                    $hits['ranking']++;
                    foreach ($thresholds as $threshold) {
                        $hits[(string) $threshold] += $match->score() >= $threshold ? 1 : 0;
                    }
                }
            }
        }

        return array_map(fn (int $count): float => Metrics::round(Metrics::ratio($count, count($duplicates))), $hits);
    }
}

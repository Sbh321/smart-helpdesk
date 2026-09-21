<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Console\Experiments\Datasets\DuplicatePairsGenerator;
use App\Support\Experiments\ExperimentOutput;
use Illuminate\Console\Command;

/**
 * `php artisan experiment:generate-duplicates --seed=42` writes `duplicates.json` (300 labelled pairs,
 * merging the hand-written pairs from `sources/duplicates-hand-written.json`) and
 * `duplicates-haystack.json` (5 000 tickets, seed + 1) for E2. Existing files are never overwritten.
 */
final class GenerateDuplicates extends Command
{
    protected $signature = 'experiment:generate-duplicates {--seed=42} {--output= : Dataset folder (default experiments/datasets/<dataset>)} {--hand-written= : Hand-written pairs (default <output>/sources/duplicates-hand-written.json)}';

    protected $description = 'Generate the E2 duplicate-pair dataset and haystack';

    public function handle(DuplicatePairsGenerator $generator): int
    {
        $seed = (int) $this->option('seed');
        $folder = is_string($path = $this->option('output')) && $path !== ''
            ? $path
            : config('helpdesk.experiments.path').'/datasets/'.config('helpdesk.experiments.dataset');
        $source = is_string($hand = $this->option('hand-written')) && $hand !== '' ? $hand : "{$folder}/sources/duplicates-hand-written.json";

        if (is_file("{$folder}/duplicates.json") || is_file("{$folder}/duplicates-haystack.json")) {
            $this->components->error("{$folder} already holds the duplicate dataset; datasets are never regenerated in place.");

            return self::FAILURE;
        }
        if (! is_file($source)) {
            $this->components->error("Hand-written pairs {$source} are missing.");

            return self::FAILURE;
        }

        $handWritten = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR)['pairs'];
        $pairs = $generator->pairs($seed, $handWritten);
        $haystack = $generator->haystack($seed + 1);
        $counts = array_count_values(array_column($pairs, 'source'));
        ksort($counts);

        $out = new ExperimentOutput($folder);
        $out->json('duplicates.json', [
            'meta' => [
                'dataset' => config('helpdesk.experiments.dataset'),
                'command' => "php artisan experiment:generate-duplicates --seed={$seed}",
                'seed' => $seed,
                'generated_on' => now()->toDateString(),
                'pairs' => count($pairs),
                'duplicates' => count(array_filter($pairs, fn (array $pair): bool => $pair['label'] === 'duplicate')),
                'non_duplicates' => count(array_filter($pairs, fn (array $pair): bool => $pair['label'] === 'non_duplicate')),
                'by_source' => $counts,
            ],
            'pairs' => $pairs,
        ]);
        // One ticket per line keeps the 5 000-ticket file small and readable.
        $meta = json_encode([
            'dataset' => config('helpdesk.experiments.dataset'),
            'command' => "php artisan experiment:generate-duplicates --seed={$seed}",
            'seed' => $seed + 1,
            'generated_on' => now()->toDateString(),
            'tickets' => count($haystack),
            'use' => 'E2 Recall@5: the 100 duplicate partners are hidden among these tickets.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lines = array_map(fn (array $ticket): string => json_encode($ticket, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $haystack);
        file_put_contents("{$folder}/duplicates-haystack.json", "{\"meta\": {$meta},\n\"tickets\": [\n".implode(",\n", $lines)."\n]}\n");

        $this->components->info(sprintf('%d pairs and %d haystack tickets written to %s', count($pairs), count($haystack), $folder));

        return self::SUCCESS;
    }
}

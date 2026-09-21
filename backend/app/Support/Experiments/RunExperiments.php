<?php

declare(strict_types=1);

namespace App\Support\Experiments;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `php artisan experiment:run e2 --strategy=baseline --seed=42` (docs/05-algorithms/evaluation-methodology.md §3).
 * Console only: experiments are never reachable over HTTP.
 */
final class RunExperiments extends Command
{
    protected $signature = 'experiment:run
        {experiment=all : e1, e2, e3, e4, e6 or all}
        {--all : Run every experiment (same as "all")}
        {--strategy=baseline : Strategy name in helpdesk.experiments.strategies}
        {--seed=42 : Seed for every random choice}
        {--output= : Result folder (default experiments/results/<dataset>)}
        {--commit= : Git commit recorded in run.json}';

    protected $description = 'Run the algorithm experiments and write their tables and run.json';

    public function handle(): int
    {
        $experiments = [];
        foreach ($this->laravel->tagged('experiments') as $experiment) {
            assert($experiment instanceof Experiment);
            $experiments[$experiment->key()] = $experiment;
        }
        ksort($experiments);

        $wanted = $this->option('all') ? 'all' : (string) $this->argument('experiment');
        $selected = $wanted === 'all' ? $experiments : array_intersect_key($experiments, [$wanted => true]);
        if ($selected === []) {
            $this->components->error("Unknown experiment {$wanted}; choose one of ".implode(', ', array_keys($experiments)).' or all.');

            return self::FAILURE;
        }

        $dataset = (string) config('helpdesk.experiments.dataset');
        $root = (string) config('helpdesk.experiments.path');
        $output = is_string($path = $this->option('output')) && $path !== '' ? $path : "{$root}/results/{$dataset}";
        $seed = (int) $this->option('seed');
        $strategy = (string) $this->option('strategy');

        foreach ($selected as $key => $experiment) {
            $started = microtime(true);
            $out = new ExperimentOutput("{$output}/{$key}");
            $context = new ExperimentContext($seed, $strategy, "{$root}/datasets/{$dataset}", $out);
            $result = $experiment->run($context);

            $out->json('run.json', [
                'experiment' => $key,
                'title' => $experiment->title(),
                'command' => "php artisan experiment:run {$key} --strategy={$strategy} --seed={$seed}",
                'seed' => $seed,
                'dataset' => $dataset,
                'strategy_selected' => $strategy,
                'strategy' => $result['strategy'],
                'settings' => $result['settings'],
                'outputs' => $out->files(),
                // Everything below changes from run to run; the reproducibility test ignores it.
                'environment' => [
                    'git_commit' => $this->commit(),
                    'php' => PHP_VERSION,
                    'postgresql' => $this->postgresVersion(),
                    'generated_at' => now()->utc()->format(DateTimeInterface::ATOM),
                    'duration_s' => round(microtime(true) - $started, 1),
                ],
            ]);
            $this->components->twoColumnDetail("{$key} {$experiment->title()}", $out->directory);
        }

        return self::SUCCESS;
    }

    private function commit(): ?string
    {
        $commit = $this->option('commit');

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    private function postgresVersion(): ?string
    {
        try {
            $row = DB::selectOne('SHOW server_version');

            return is_object($row) ? (string) $row->server_version : null;
        } catch (Throwable) {
            return null;
        }
    }
}

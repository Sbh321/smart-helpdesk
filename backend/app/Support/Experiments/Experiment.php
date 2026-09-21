<?php

declare(strict_types=1);

namespace App\Support\Experiments;

/**
 * One reproducible experiment of the result analysis (docs/12-academic/result-analysis-plan.md).
 * Modules tag their experiments with `experiments` in their service provider; `experiment:run` finds them.
 */
interface Experiment
{
    /** Short key used on the command line and as the result folder, e.g. `e1`. */
    public function key(): string;

    public function title(): string;

    /**
     * Runs the experiment, writes its tables through `$context->output` and returns what `run.json`
     * records about the strategy under test.
     *
     * @return array{strategy: array{name: string, version: string, class: string}, settings: array<string, mixed>}
     */
    public function run(ExperimentContext $context): array;
}

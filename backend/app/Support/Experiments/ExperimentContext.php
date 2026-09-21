<?php

declare(strict_types=1);

namespace App\Support\Experiments;

use LogicException;
use RuntimeException;

/**
 * What an experiment gets from `experiment:run`: the seed, the name of the strategy under test,
 * the dataset folder and the output for its tables.
 */
final readonly class ExperimentContext
{
    public function __construct(
        public int $seed,
        public string $strategy,
        public string $datasetPath,
        public ExperimentOutput $output,
    ) {}

    public function random(): SeededRandom
    {
        return new SeededRandom($this->seed);
    }

    /** Absolute path of a dataset file, which must exist. */
    public function dataset(string $relative): string
    {
        $path = $this->datasetPath.'/'.$relative;
        if (! is_file($path)) {
            throw new RuntimeException("Dataset file {$path} is missing; see experiments/README.md.");
        }

        return $path;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function datasetJson(string $relative): array
    {
        return (array) json_decode((string) file_get_contents($this->dataset($relative)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Resolves the strategy under test by name (`--strategy=baseline`) from
     * `helpdesk.experiments.strategies.<decision>`, so a replacement runs on the same data (ADR-0023 §2).
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  array<string, mixed>  $parameters  constructor arguments, e.g. the settings
     * @return T
     */
    public function strategy(string $decision, string $contract, array $parameters = []): object
    {
        $class = config("helpdesk.experiments.strategies.{$decision}.{$this->strategy}");
        if (! is_string($class) || ! is_subclass_of($class, $contract)) {
            throw new LogicException("No {$decision} strategy named '{$this->strategy}' implements {$contract}; see helpdesk.experiments.strategies.");
        }

        $strategy = app()->makeWith($class, $parameters);
        assert($strategy instanceof $contract);

        return $strategy;
    }
}

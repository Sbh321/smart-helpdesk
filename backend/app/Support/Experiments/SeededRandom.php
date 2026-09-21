<?php

declare(strict_types=1);

namespace App\Support\Experiments;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded random numbers for experiments and dataset generators (docs/05-algorithms/evaluation-methodology.md §3).
 * The same seed gives the same sequence on every machine (Mersenne Twister engine).
 */
final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** A whole number between `$min` and `$max`, both included. */
    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /** A number in [0, 1). */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    /** True with probability `$p`. */
    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    /**
     * Exponentially distributed value with the given mean (inverse transform: −mean · ln(1 − u)).
     * Used for inter-arrival and handling times.
     */
    public function exponential(float $mean): float
    {
        return -$mean * log(1.0 - $this->float());
    }

    /**
     * One element, uniformly.
     *
     * @template T
     *
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * One key, with probability proportional to its weight.
     *
     * @param  array<string, int|float>  $weights
     */
    public function weighted(array $weights): string
    {
        $roll = $this->float() * array_sum($weights);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll < 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * A shuffled copy.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return array_values($this->randomizer->shuffleArray($items));
    }
}

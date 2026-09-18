<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;

/**
 * Load-spread measures for experiment E1 (docs/05-algorithms/agent-assignment.md §Evaluation).
 */
final class FairnessIndex
{
    /**
     * Jain's fairness index (Σx)² ÷ (n · Σx²): 1 when every value is equal, 1/n when one value holds everything.
     * An empty list or all-zero values count as perfectly fair.
     *
     * @param  list<int|float>  $values  non-negative loads
     */
    public static function jain(array $values): float
    {
        self::assertNonNegative($values);

        $sumOfSquares = array_sum(array_map(static fn (int|float $x): float => $x * $x, $values));

        if ($values === [] || $sumOfSquares <= 0) {
            return 1.0;
        }

        return array_sum($values) ** 2 / (count($values) * $sumOfSquares);
    }

    /**
     * Population standard deviation.
     *
     * @param  list<int|float>  $values
     */
    public static function standardDeviation(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(static fn (int|float $x): float => ($x - $mean) ** 2, $values)) / count($values);

        return sqrt($variance);
    }

    /**
     * Coefficient of variation: standard deviation ÷ mean (0 when the mean is 0).
     *
     * @param  list<int|float>  $values  non-negative loads
     */
    public static function coefficientOfVariation(array $values): float
    {
        self::assertNonNegative($values);

        $mean = $values === [] ? 0 : array_sum($values) / count($values);

        return $mean <= 0 ? 0.0 : self::standardDeviation($values) / $mean;
    }

    /**
     * @param  list<int|float>  $values
     */
    private static function assertNonNegative(array $values): void
    {
        foreach ($values as $value) {
            if ($value < 0) {
                throw new InvalidStrategySettings('Fairness measures need non-negative values.');
            }
        }
    }
}

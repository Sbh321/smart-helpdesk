<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Stats;

use InvalidArgumentException;

/**
 * Summary statistics for report measures (docs/05-algorithms/history-and-time-analytics.md §6).
 *
 * Percentiles use linear interpolation between closest ranks, the same method as PostgreSQL
 * `percentile_cont`: position = f · (n − 1) on the sorted values (0-based), and the result is
 * interpolated between the neighbours of that position. So SQL reports and PHP reference values agree.
 * Empty input gives null (as the SQL aggregates do).
 */
final class Percentile
{
    /**
     * @param  list<int|float>  $values
     */
    public static function continuous(array $values, float $fraction): ?float
    {
        if (! ($fraction >= 0.0 && $fraction <= 1.0)) {   // also false for NaN
            throw new InvalidArgumentException('A percentile fraction must be between 0 and 1.');
        }

        if ($values === []) {
            return null;
        }

        $sorted = self::sorted($values);
        $position = $fraction * (count($sorted) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $sorted[$lower] + ($position - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function median(array $values): ?float
    {
        return self::continuous($values, 0.5);
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function p90(array $values): ?float
    {
        return self::continuous($values, 0.9);
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum(self::sorted($values)) / count($values);
    }

    /**
     * @param  list<int|float>  $values
     * @return list<float>
     */
    private static function sorted(array $values): array
    {
        $floats = [];

        foreach ($values as $value) {
            if (! is_finite((float) $value)) {
                throw new InvalidArgumentException('Statistics need finite numbers.');
            }

            $floats[] = (float) $value;
        }

        sort($floats);

        return $floats;
    }
}

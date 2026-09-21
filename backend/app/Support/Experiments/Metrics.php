<?php

declare(strict_types=1);

namespace App\Support\Experiments;

/**
 * Small measures used by the experiments (docs/05-algorithms/evaluation-methodology.md §1).
 * Division by zero gives 0, which is how the tables report an empty class.
 */
final class Metrics
{
    /** TP ÷ (TP + FP) */
    public static function precision(int $truePositives, int $falsePositives): float
    {
        return self::ratio($truePositives, $truePositives + $falsePositives);
    }

    /** TP ÷ (TP + FN) */
    public static function recall(int $truePositives, int $falseNegatives): float
    {
        return self::ratio($truePositives, $truePositives + $falseNegatives);
    }

    /** 2 · P · R ÷ (P + R) */
    public static function f1(float $precision, float $recall): float
    {
        return $precision + $recall <= 0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall);
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    public static function ratio(int|float $part, int|float $whole): float
    {
        return $whole === 0 ? 0.0 : $part / $whole;
    }

    /** Rounded for tables, so outputs do not depend on the last bits of a float. */
    public static function round(float $value, int $places = 4): float
    {
        return round($value, $places) + 0.0;
    }
}

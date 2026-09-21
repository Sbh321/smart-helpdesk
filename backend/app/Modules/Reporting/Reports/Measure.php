<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/**
 * A number per group: an SQL aggregate over the report's source. `unit` tells the client how to show
 * it: count, seconds (a duration), percent (0–100), ratio or number.
 */
final readonly class Measure
{
    public function __construct(
        public string $label,
        public string $sql,
        public string $unit = 'count',
    ) {}

    public static function count(string $label, ?string $condition = null): self
    {
        return new self($label, $condition === null ? 'count(*)' : "count(*) FILTER (WHERE {$condition})");
    }

    public static function median(string $label, string $column, string $unit = 'seconds'): self
    {
        return new self($label, "percentile_cont(0.5) WITHIN GROUP (ORDER BY {$column})", $unit);
    }

    public static function p90(string $label, string $column, string $unit = 'seconds'): self
    {
        return new self($label, "percentile_cont(0.9) WITHIN GROUP (ORDER BY {$column})", $unit);
    }

    public static function average(string $label, string $column, string $unit = 'seconds'): self
    {
        return new self($label, "avg({$column})", $unit);
    }

    /** 100 × part / whole, null when the whole is 0 (rates are shown with their denominator). */
    public static function rate(string $label, string $part, string $whole): self
    {
        return new self($label, "round(100.0 * count(*) FILTER (WHERE {$part}) / nullif(count(*) FILTER (WHERE {$whole}), 0), 1)", 'percent');
    }
}

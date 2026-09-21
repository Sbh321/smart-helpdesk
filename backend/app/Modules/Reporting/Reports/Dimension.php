<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/**
 * A grouping axis. `sql` is an expression over the report's source; `{tz}` is replaced by the
 * bound workspace time zone. `labels` names a lookup (`teams`, `agents`, `categories`, …) that turns
 * the keys into display names.
 */
final readonly class Dimension
{
    public function __construct(
        public string $label,
        public string $sql,
        public ?string $labels = null,
        public bool $isTime = false,
    ) {}

    public static function day(string $column): self
    {
        return new self('Day', "to_char(({$column}) AT TIME ZONE {tz}, 'YYYY-MM-DD')", isTime: true);
    }

    public static function week(string $column): self
    {
        return new self('Week', "to_char(date_trunc('week', ({$column}) AT TIME ZONE {tz}), 'YYYY-MM-DD')", isTime: true);
    }

    public static function month(string $column): self
    {
        return new self('Month', "to_char(date_trunc('month', ({$column}) AT TIME ZONE {tz}), 'YYYY-MM')", isTime: true);
    }
}

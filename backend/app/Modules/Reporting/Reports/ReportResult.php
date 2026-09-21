<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/**
 * What a run returns: one row per dimension value, the totals over the whole period, and the totals of
 * the previous period (comparison), which are null when not asked for or suppressed for a small sample.
 */
final readonly class ReportResult
{
    /**
     * @param  list<array{key: string, label: string, values: array<string, int|float|null>}>  $rows
     * @param  array<string, int|float|null>  $totals
     * @param  array<string, int|float|null>|null  $previous
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public ?array $previous = null,
        public bool $truncated = false,
    ) {}
}

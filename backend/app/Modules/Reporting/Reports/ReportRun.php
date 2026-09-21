<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/** One run as the API returns it (a typed view, so the API schema can be inferred). */
final readonly class ReportRun
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<array{key: string, label: string, values: array<string, int|float|null>}>  $rows
     * @param  array<string, int|float|null>  $totals
     * @param  array<string, int|float|null>|null  $previous
     */
    public function __construct(
        public string $report,
        public array $parameters,
        public array $rows,
        public array $totals,
        public ?array $previous,
        public bool $truncated,
    ) {}
}

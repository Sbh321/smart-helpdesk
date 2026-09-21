<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Dashboard;

/** The dashboard as the API returns it (a typed view, so the API schema can be inferred). */
final readonly class DashboardView
{
    /**
     * @param  list<array{key: string, label: string, unit: string, value: int|float|null, previous: int|float|null, report: string, measure: string}>  $kpis
     * @param  list<array{key: string, title: string, chart: string, report: string, report_title: string, parameters: array{period: string, group: string, measures: list<string>}, measures: list<array{key: string, label: string, unit: string}>, rows: list<array{key: string, label: string, values: array<string, int|float|null>}>, truncated: bool}>  $series
     */
    public function __construct(
        public string $period,
        public string $from,
        public string $to,
        public string $timezone,
        public array $kpis,
        public array $series,
    ) {}
}

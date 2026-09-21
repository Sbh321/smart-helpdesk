<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

/** A report definition as the API describes it (a typed view, so the API schema can be inferred). */
final readonly class ReportDescription
{
    /**
     * @param  list<string>  $periods
     * @param  list<array{key: string, label: string, is_time: bool}>  $dimensions
     * @param  list<array{key: string, label: string, unit: string}>  $measures
     * @param  list<array{key: string, label: string, type: string, labels: string|null}>  $filters
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $group,
        public string $chart,
        public string $defaultDimension,
        public ?string $drillDownTo,
        public array $periods,
        public array $dimensions,
        public array $measures,
        public array $filters,
    ) {}

    public static function of(ReportDefinition $report): self
    {
        return new self(
            $report->key(),
            $report->title(),
            $report->description(),
            $report->group(),
            $report->chart(),
            $report->defaultDimension(),
            $report->drillDownTo(),
            ReportRunner::PERIODS,
            array_map(fn (string $key, Dimension $d): array => ['key' => $key, 'label' => $d->label, 'is_time' => $d->isTime], array_keys($report->dimensions()), $report->dimensions()),
            array_map(fn (string $key, Measure $m): array => ['key' => $key, 'label' => $m->label, 'unit' => $m->unit], array_keys($report->measures()), $report->measures()),
            array_map(fn (string $key, Filter $f): array => ['key' => $key, 'label' => $f->label, 'type' => $f->type, 'labels' => $f->labels], array_keys($report->filters()), $report->filters()),
        );
    }
}

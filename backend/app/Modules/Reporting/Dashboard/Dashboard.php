<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Dashboard;

use App\Models\User;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportDefinition;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\ReportResult;
use App\Modules\Reporting\Reports\ReportRunner;
use LogicException;

/**
 * The management dashboard (FR-ANL, roadmap M3-01): a fixed selection of catalogue reports run through
 * `ReportRunner`, so every number equals the same report run with the same parameters and is cached with
 * it. No SQL of its own. A tile or series whose report the caller may not run is left out.
 */
final readonly class Dashboard
{
    /**
     * Tile key => [report, measure, label, trend series]. The trend series is a key of SERIES whose rows
     * carry the same measure of the same report over time; the SPA draws it as the tile's sparkline
     * (roadmap M4-08). Null where no such series exists: a sparkline is never made up.
     */
    public const array KPIS = [
        'created' => ['rpt-t01', 'created', 'Tickets created', 'volume'],
        'resolved' => ['rpt-t01', 'resolved', 'Tickets resolved', 'volume'],
        'open_now' => ['rpt-t05', 'open', 'Open now', null],
        'first_response_median' => ['rpt-t06', 'first_response_median', 'Median first response', null],
        'resolution_median' => ['rpt-t06', 'resolution_median', 'Median resolution', null],
        'sla_compliance' => ['rpt-s01', 'compliance', 'SLA compliance', 'sla_compliance'],
        'sla_breaches' => ['rpt-s01', 'breached', 'SLA breaches', null],
        'reopen_rate' => ['rpt-t07', 'reopen_rate', 'Reopen rate', null],
    ];

    /**
     * Series key => [report, group, measures, title, chart (line, stacked_area or bar), section]. The
     * section orders the page (roadmap M4-08): `trend` is over time, `breakdown` splits the period.
     */
    public const array SERIES = [
        'volume' => ['rpt-t01', 'day', ['created', 'resolved'], 'Created and resolved per day', 'line', 'trend'],
        'backlog' => ['rpt-t02', 'day', ['end_backlog'], 'Backlog at the end of each day', 'stacked_area', 'trend'],
        'sla_compliance' => ['rpt-s01', 'week', ['compliance'], 'SLA compliance per week', 'line', 'trend'],
        'by_channel' => ['rpt-t01', 'channel', ['created'], 'Tickets created by channel', 'bar', 'breakdown'],
        'response_by_priority' => ['rpt-t06', 'priority', ['first_response_median', 'resolution_median'], 'Response and resolution by priority', 'bar', 'breakdown'],
        'open_by_team' => ['rpt-t05', 'team', ['open'], 'Open tickets by team', 'bar', 'breakdown'],
        'ageing' => ['rpt-t05', 'age_bucket', ['open'], 'Open tickets by age', 'bar', 'breakdown'],
        'agent_workload' => ['rpt-a01', 'agent', ['backlog'], 'Open assigned tickets per agent', 'bar', 'breakdown'],
        'time_in_status' => ['rpt-t03', 'status', ['median_wall'], 'Median time in status', 'bar', 'breakdown'],
    ];

    public function __construct(private ReportCatalogue $catalogue, private ReportRunner $runner) {}

    public function for(User $user, string $period, string $tenantId, string $timezone): DashboardView
    {
        $bounds = $this->parameters($this->report('rpt-t01'), ['period' => $period], $tenantId, $timezone);

        // One run per report for the tiles: the report's default group, the tile measures, with comparison.
        $kpis = [];
        /** @var array<string, ReportResult> $totals */
        $totals = [];
        foreach (self::KPIS as $key => [$reportKey, $measure, $label, $trend]) {
            $report = $this->report($reportKey);
            if (! $this->catalogue->allows($user, $report)) {
                continue;
            }
            $totals[$reportKey] ??= $this->runner->run($report, $this->parameters($report, [
                'period' => $period,
                'measures' => implode(',', $this->kpiMeasures($reportKey)),
                'compare' => true,
            ], $tenantId, $timezone));
            $result = $totals[$reportKey];
            $kpis[] = [
                'key' => $key,
                'label' => $label,
                'unit' => $this->measure($report, $measure)->unit,
                'value' => $result->totals[$measure] ?? null,
                'previous' => $result->previous === null ? null : ($result->previous[$measure] ?? null),
                'report' => $reportKey,
                'measure' => $measure,
                'trend_series' => $trend,
            ];
        }

        $series = [];
        foreach (self::SERIES as $key => [$reportKey, $group, $measures, $title, $chart, $section]) {
            $report = $this->report($reportKey);
            if (! $this->catalogue->allows($user, $report)) {
                continue;
            }
            $parameters = $this->parameters($report, ['period' => $period, 'group' => $group, 'measures' => implode(',', $measures)], $tenantId, $timezone);
            $result = $this->runner->run($report, $parameters);
            $series[] = [
                'key' => $key,
                'title' => $title,
                'chart' => $chart,
                'section' => $section,
                'report' => $reportKey,
                'report_title' => $report->title(),
                'parameters' => ['period' => $period, 'group' => $group, 'measures' => $measures],
                'measures' => array_map(fn (string $m): array => ['key' => $m, 'label' => $this->measure($report, $m)->label, 'unit' => $this->measure($report, $m)->unit], $measures),
                'rows' => $result->rows,
                'truncated' => $result->truncated,
            ];
        }

        return new DashboardView(
            $period,
            $bounds->from->toIso8601ZuluString(),
            $bounds->to->toIso8601ZuluString(),
            $timezone,
            $kpis,
            $series,
        );
    }

    /** @return list<string> the tile measures of one report */
    private function kpiMeasures(string $reportKey): array
    {
        return array_values(array_unique(array_map(fn (array $kpi): string => $kpi[1], array_filter(self::KPIS, fn (array $kpi): bool => $kpi[0] === $reportKey))));
    }

    /** @param array<string, mixed> $input */
    private function parameters(ReportDefinition $report, array $input, string $tenantId, string $timezone): ReportParameters
    {
        return $this->runner->parameters($report, $input, $tenantId, $timezone);
    }

    private function report(string $key): ReportDefinition
    {
        return $this->catalogue->find($key) ?? throw new LogicException("The dashboard names an unknown report {$key}.");
    }

    private function measure(ReportDefinition $report, string $key): Measure
    {
        return $report->measures()[$key] ?? throw new LogicException("Report {$report->key()} has no measure {$key}.");
    }
}

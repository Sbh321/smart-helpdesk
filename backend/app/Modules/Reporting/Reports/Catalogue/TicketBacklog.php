<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\DimensionLabels;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportDefinition;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RPT-T02 Backlog over time: open tickets at the end of each day of the period, from the daily
 * snapshots. Grouped by day it is the workspace's backlog curve; grouped by team, agent, priority or
 * status it gives each value's backlog at the end of the period and its daily average.
 */
final class TicketBacklog implements ReportDefinition
{
    private const array SNAPSHOT_DIMENSION = ['day' => 'none', 'team' => 'team', 'agent' => 'agent', 'priority' => 'priority', 'status' => 'status'];

    public function key(): string
    {
        return 'rpt-t02';
    }

    public function title(): string
    {
        return 'Backlog over time';
    }

    public function description(): string
    {
        return 'Tickets not yet resolved or closed at the end of each day.';
    }

    public function group(): string
    {
        return 'tickets';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function chart(): string
    {
        return 'stacked_area';
    }

    public function defaultDimension(): string
    {
        return 'day';
    }

    public function drillDownTo(): ?string
    {
        return null;
    }

    public function dimensions(): array
    {
        return [
            'day' => new Dimension('Day', 's.day', isTime: true),
            'team' => new Dimension('Team', 's.dimension_key', 'teams'),
            'agent' => new Dimension('Agent', 's.dimension_key', 'agents'),
            'priority' => new Dimension('Priority', 's.dimension_key', 'priority'),
            'status' => new Dimension('Status', 's.dimension_key', 'status'),
        ];
    }

    public function measures(): array
    {
        return [
            'end_backlog' => new Measure('Backlog at the end of the period (or day)', 'end', 'count'),
            'average_backlog' => new Measure('Average daily backlog', 'avg', 'number'),
            'peak_backlog' => new Measure('Highest daily backlog', 'max', 'count'),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        [$first, $last] = $this->days($parameters->from, $parameters->to, $parameters->timezone);
        $snapshot = self::SNAPSHOT_DIMENSION[$parameters->dimension];
        $grouped = $parameters->dimension === 'day' ? 's.day::text' : 's.dimension_key';

        $rows = DB::select(<<<SQL
            SELECT {$grouped} AS k,
                   (array_agg((s.metrics ->> 'backlog')::int ORDER BY s.day DESC))[1] AS end_backlog,
                   round(avg((s.metrics ->> 'backlog')::int), 1) AS average_backlog,
                   max((s.metrics ->> 'backlog')::int) AS peak_backlog
            FROM report_daily_snapshots s
            WHERE s.tenant_id = ? AND s.dimension = ? AND s.day BETWEEN ?::date AND ?::date
            GROUP BY 1 ORDER BY 1
            SQL, [$parameters->tenantId, $snapshot, $first, $last]);

        $labels = app(DimensionLabels::class)->for($this->dimensions()[$parameters->dimension]->labels, array_map(fn (object $row): string => (string) $row->k, $rows));
        $pick = fn (object $row): array => array_intersect_key(
            ['end_backlog' => (int) $row->end_backlog, 'average_backlog' => (float) $row->average_backlog, 'peak_backlog' => (int) $row->peak_backlog],
            array_flip($parameters->measures),
        );

        $totals = DB::selectOne(<<<'SQL'
            SELECT (array_agg((metrics ->> 'backlog')::int ORDER BY day DESC))[1] AS end_backlog,
                   round(avg((metrics ->> 'backlog')::int), 1) AS average_backlog,
                   max((metrics ->> 'backlog')::int) AS peak_backlog
            FROM report_daily_snapshots WHERE tenant_id = ? AND dimension = 'none' AND day BETWEEN ?::date AND ?::date
            SQL, [$parameters->tenantId, $first, $last]);

        return new ReportResult(
            array_map(fn (object $row): array => ['key' => (string) $row->k, 'label' => $labels[(string) $row->k] ?? (string) $row->k, 'values' => $pick($row)], $rows),
            $totals?->end_backlog === null ? array_fill_keys($parameters->measures, null) : $pick($totals),
        );
    }

    /** @return array{string, string} the first and last local day of the period */
    private function days(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        return [$from->setTimezone($timezone)->toDateString(), $to->setTimezone($timezone)->subSecond()->toDateString()];
    }
}

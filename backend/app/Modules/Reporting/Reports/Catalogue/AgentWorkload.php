<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-A01 Agent workload over time, from the daily snapshots: the tickets each agent (or team) held open
 * at the end of each day and, for agents, the load (backlog ÷ capacity) as capacity utilisation. Grouped
 * by day it is the total over the agents; grouped by agent or team it is the daily average of the period.
 */
final class AgentWorkload extends SqlReport
{
    public function key(): string
    {
        return 'rpt-a01';
    }

    public function title(): string
    {
        return 'Agent workload over time';
    }

    public function description(): string
    {
        return 'Open assigned tickets at the end of each day and capacity utilisation, per agent or team.';
    }

    public function group(): string
    {
        return 'agents';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['agents.view', 'tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'day';
    }

    public function chart(): string
    {
        return 'line';
    }

    protected function source(): string
    {
        return $this->snapshots('agent');
    }

    protected function sourceFor(ReportParameters $parameters): string
    {
        return $this->snapshots($parameters->dimension === 'team' ? 'team' : 'agent');
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 's.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 's.day_start';
    }

    public function dimensions(): array
    {
        return [
            'day' => new Dimension('Day', "to_char(s.day, 'YYYY-MM-DD')", isTime: true),
            'week' => new Dimension('Week', "to_char(date_trunc('week', s.day), 'YYYY-MM-DD')", isTime: true),
            'agent' => new Dimension('Agent', 's.dimension_key', 'agents'),
            'team' => new Dimension('Team', 's.dimension_key', 'teams'),
        ];
    }

    public function measures(): array
    {
        return [
            'backlog' => new Measure('Open assigned tickets (daily average)', 'round(sum(s.backlog)::numeric / nullif(count(DISTINCT s.day), 0), 1)', 'number'),
            'peak_backlog' => new Measure('Highest backlog of one agent or team', 'max(s.backlog)'),
            'utilisation' => new Measure('Capacity utilisation (agents)', 'round(100 * avg(s.load), 1)', 'percent'),
        ];
    }

    /** The day's snapshot rows of one dimension, without the unassigned `-` key; the day starts at local midnight. */
    private function snapshots(string $dimension): string
    {
        return <<<SQL
            (
                SELECT tenant_id, day, (day::timestamp AT TIME ZONE {tz}) AS day_start, dimension_key,
                       (metrics ->> 'backlog')::int AS backlog, (metrics ->> 'load')::numeric AS load
                FROM report_daily_snapshots
                WHERE tenant_id = ? AND dimension = '{$dimension}' AND dimension_key <> '-'
            ) s
            SQL;
    }
}

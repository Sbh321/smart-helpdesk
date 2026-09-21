<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-T03 Time in status: how long the status intervals that started in the period lasted, wall-clock
 * and business time. An interval still open counts wall-clock time up to the end of the period (or now);
 * its business time is not known yet, so business measures cover closed intervals only.
 */
final class TimeInStatus extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t03';
    }

    public function title(): string
    {
        return 'Time in status';
    }

    public function description(): string
    {
        return 'How long tickets stayed in each status, for the status periods that started in the period.';
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

    public function defaultDimension(): string
    {
        return 'status';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'i.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT i.*, coalesce(i.wall_seconds, greatest(0, extract(epoch FROM (?::timestamptz - i.starts_at))))::bigint AS wall_s
                FROM report_ticket_intervals i WHERE i.tenant_id = ?
            ) i
            JOIN report_ticket_facts f ON f.tenant_id = i.tenant_id AND f.ticket_id = i.ticket_id
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$this->until($to), $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'i.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'i.starts_at';
    }

    public function dimensions(): array
    {
        return [
            'status' => new Dimension('Status', 'i.status', 'status'),
            'priority' => new Dimension('Priority', 'i.priority_level', 'priority'),
            'team' => new Dimension('Team', 'i.team_id', 'teams'),
            'agent' => new Dimension('Agent', 'i.assigned_agent_id', 'agents'),
            'category' => new Dimension('Category', 'f.category_id', 'categories'),
        ];
    }

    public function measures(): array
    {
        return [
            'intervals' => Measure::count('Status periods'),
            'open' => Measure::count('Still in the status', 'i.ends_at IS NULL'),
            'average_wall' => Measure::average('Average (wall-clock)', 'i.wall_s'),
            'median_wall' => Measure::median('Median (wall-clock)', 'i.wall_s'),
            'p90_wall' => Measure::p90('90th percentile (wall-clock)', 'i.wall_s'),
            'average_business' => Measure::average('Average (business)', 'i.business_seconds'),
            'median_business' => Measure::median('Median (business)', 'i.business_seconds'),
            'p90_business' => Measure::p90('90th percentile (business)', 'i.business_seconds'),
        ];
    }

    public function filters(): array
    {
        return [
            'status' => new Filter('Status', 'i.status', labels: 'status'),
            'priority' => new Filter('Priority', 'i.priority_level', labels: 'priority'),
            'team' => new Filter('Team', 'i.team_id', labels: 'teams'),
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
        ];
    }
}

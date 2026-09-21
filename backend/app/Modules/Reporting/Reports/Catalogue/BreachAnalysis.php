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
 * RPT-S02 Breach analysis: the SLA timers that breached in the period, by cause (no first response in
 * time, late resolution), and how late they were: wall-clock from the due time to when the timer was met
 * or cancelled, or to the end of the period (or now) while it still runs.
 */
final class BreachAnalysis extends SqlReport
{
    public function key(): string
    {
        return 'rpt-s02';
    }

    public function title(): string
    {
        return 'Breach analysis';
    }

    public function description(): string
    {
        return 'SLA breaches in the period by cause, with the average and median lateness.';
    }

    public function group(): string
    {
        return 'sla';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'cause';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'b.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tm.tenant_id, tm.ticket_id, tm.kind, tm.breached_at, t.team_id, t.category_id,
                       coalesce(t.priority_override_level, t.priority_level) AS priority_level,
                       greatest(0, extract(epoch FROM (coalesce(tm.met_at, tm.cancelled_at, ?::timestamptz) - tm.due_at))) AS late_s
                FROM ticket_sla_timers tm JOIN tickets t ON t.tenant_id = tm.tenant_id AND t.id = tm.ticket_id
                WHERE tm.tenant_id = ? AND tm.breached_at IS NOT NULL
            ) b
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$this->until($to), $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'b.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'b.breached_at';
    }

    public function dimensions(): array
    {
        return [
            'cause' => new Dimension('Cause', 'b.kind', 'breach_cause'),
            'team' => new Dimension('Team', 'b.team_id', 'teams'),
            'category' => new Dimension('Category', 'b.category_id', 'categories'),
            'priority' => new Dimension('Priority', 'b.priority_level', 'priority'),
            'week' => Dimension::week('b.breached_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'breaches' => Measure::count('Breaches'),
            'tickets' => new Measure('Tickets', 'count(DISTINCT b.ticket_id)'),
            'average_lateness' => Measure::average('Average lateness (wall-clock)', 'b.late_s'),
            'median_lateness' => Measure::median('Median lateness (wall-clock)', 'b.late_s'),
        ];
    }

    public function filters(): array
    {
        return [
            'cause' => new Filter('Cause', 'b.kind', labels: 'breach_cause'),
            'team' => new Filter('Team', 'b.team_id', labels: 'teams'),
        ];
    }
}

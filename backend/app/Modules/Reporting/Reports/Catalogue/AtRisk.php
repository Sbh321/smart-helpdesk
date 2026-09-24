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
 * RPT-S04 At-risk list: the SLA timers running now that have passed their warning time but not their due
 * time, soonest due first, with the wall-clock time remaining. The period does not apply.
 */
final class AtRisk extends SqlReport
{
    public function key(): string
    {
        return 'rpt-s04';
    }

    public function title(): string
    {
        return 'At-risk list';
    }

    public function description(): string
    {
        return 'Running SLA timers in warning now, with the time remaining. The period does not apply.';
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
        return 'ticket';
    }

    public function chart(): string
    {
        return 'table';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'r.ticket_id';
    }

    public function periodApplies(): bool
    {
        return false;
    }

    protected function orderBy(): string
    {
        return 'min(r.remaining_s), 1';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tm.tenant_id, tm.ticket_id, tm.kind, t.team_id, t.assigned_agent_id,
                       coalesce(t.priority_override_level, t.priority_level) AS priority_level,
                       extract(epoch FROM (tm.due_at - ?::timestamptz)) AS remaining_s
                FROM ticket_sla_timers tm JOIN tickets t ON t.tenant_id = tm.tenant_id AND t.id = tm.ticket_id
                WHERE tm.tenant_id = ? AND tm.state IN ('running', 'warning') AND tm.breached_at IS NULL
                  AND tm.warning_at <= ?::timestamptz AND tm.due_at > ?::timestamptz
            ) r
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $now = $this->now();

        return [$now, $parameters->tenantId, $now, $now];
    }

    protected function tenantColumn(): string
    {
        return 'r.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'r.remaining_s';
    }

    public function dimensions(): array
    {
        return [
            'ticket' => new Dimension('Ticket', 'r.ticket_id', 'tickets'),
            'team' => new Dimension('Team', 'r.team_id', 'teams'),
            'agent' => new Dimension('Agent', 'r.assigned_agent_id', 'agents'),
            'priority' => new Dimension('Priority', 'r.priority_level', 'priority'),
            'kind' => new Dimension('Timer', 'r.kind', 'timer_kind'),
        ];
    }

    public function measures(): array
    {
        return [
            'timers' => Measure::count('Timers at risk'),
            'remaining' => new Measure('Least time remaining', 'min(r.remaining_s)', 'seconds'),
        ];
    }

    public function filters(): array
    {
        return [
            'team' => new Filter('Team', 'r.team_id', labels: 'teams'),
            'agent' => new Filter('Agent', 'r.assigned_agent_id', labels: 'agents'),
            'priority' => new Filter('Priority', 'r.priority_level', labels: 'priority'),
        ];
    }
}

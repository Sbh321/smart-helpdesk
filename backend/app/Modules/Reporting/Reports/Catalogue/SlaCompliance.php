<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-S01 SLA compliance of the timers started in the period (every cycle): met, breached (a timer
 * that breached counts as breached even if it was met later) and compliance = met ÷ (met + breached).
 * Timers still running and cancelled ones are shown but not in the rate.
 */
final class SlaCompliance extends SqlReport
{
    private const string BREACHED = 'tm.breached_at IS NOT NULL';

    private const string MET = "tm.state = 'met' AND tm.breached_at IS NULL";

    public function key(): string
    {
        return 'rpt-s01';
    }

    public function title(): string
    {
        return 'SLA compliance';
    }

    public function description(): string
    {
        return 'SLA timers met and breached, and the compliance rate, for the timers started in the period.';
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
        return 'week';
    }

    public function chart(): string
    {
        return 'stacked_bar';
    }

    /** The parts of the whole, which do not overlap (M4-09). */
    public function chartMeasures(): array
    {
        return ['met', 'breached', 'running'];
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'tm.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            ticket_sla_timers tm
            JOIN tickets t ON t.tenant_id = tm.tenant_id AND t.id = tm.ticket_id
            LEFT JOIN organizations o ON o.tenant_id = t.tenant_id AND o.id = t.organization_id
            SQL;
    }

    protected function tenantColumn(): string
    {
        return 'tm.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'tm.started_at';
    }

    public function dimensions(): array
    {
        return [
            'day' => Dimension::day('tm.started_at'),
            'week' => Dimension::week('tm.started_at'),
            'policy' => new Dimension('SLA policy', 'tm.policy_id', 'sla_policies'),
            'calendar' => new Dimension('Business calendar', 'tm.calendar_id', 'calendars'),
            'priority' => new Dimension('Priority', 'coalesce(t.priority_override_level, t.priority_level)', 'priority'),
            'team' => new Dimension('Team', 't.team_id', 'teams'),
            'tier' => new Dimension('Customer tier', "coalesce(o.tier, 'standard')", 'tier'),
            'kind' => new Dimension('Timer', 'tm.kind', 'timer_kind'),
        ];
    }

    public function measures(): array
    {
        return [
            'timers' => Measure::count('Timers'),
            'met' => Measure::count('Met', self::MET),
            'breached' => Measure::count('Breached', self::BREACHED),
            'running' => Measure::count('Still running', "tm.state IN ('running', 'warning', 'paused')"),
            'compliance' => Measure::rate('Compliance', self::MET, self::MET.' OR '.self::BREACHED),
        ];
    }

    public function filters(): array
    {
        return [
            'kind' => new Filter('Timer', 'tm.kind', labels: 'timer_kind'),
            'policy' => new Filter('SLA policy', 'tm.policy_id', labels: 'sla_policies'),
            'priority' => new Filter('Priority', 'coalesce(t.priority_override_level, t.priority_level)', labels: 'priority'),
            'team' => new Filter('Team', 't.team_id', labels: 'teams'),
        ];
    }
}

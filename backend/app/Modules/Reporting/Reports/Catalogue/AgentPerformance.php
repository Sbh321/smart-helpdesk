<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-A02 Agent performance over the tickets created in the period, by the agent they are assigned to
 * now: resolved, first replies, median handling time (resolution minus time pending on the customer)
 * and resolution time, SLA compliance and reopen rate.
 */
final class AgentPerformance extends SqlReport
{
    public function key(): string
    {
        return 'rpt-a02';
    }

    public function title(): string
    {
        return 'Agent performance';
    }

    public function description(): string
    {
        return 'Tickets, resolutions, response and resolution times, SLA compliance and reopens per assigned agent.';
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
        return 'agent';
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
        return 'f.ticket_id';
    }

    protected function source(): string
    {
        return 'report_ticket_facts f';
    }

    protected function tenantColumn(): string
    {
        return 'f.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'f.created_at';
    }

    public function dimensions(): array
    {
        return [
            'agent' => new Dimension('Agent', 'f.assigned_agent_id', 'agents'),
            'team' => new Dimension('Team', 'f.team_id', 'teams'),
            'week' => Dimension::week('f.created_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'assigned' => Measure::count('Assigned tickets', 'f.assigned_agent_id IS NOT NULL'),
            'resolved' => Measure::count('Resolved', 'f.resolved_at IS NOT NULL'),
            'first_replies' => Measure::count('First replies', 'f.first_responded_at IS NOT NULL'),
            'handling_median' => Measure::median('Handling time, median (wall-clock minus pending)', 'greatest(0, f.resolution_wall_s - f.pending_s)'),
            'resolution_median' => Measure::median('Resolution, median (business)', 'f.resolution_business_s'),
            'sla_compliance' => FactMeasures::slaCompliance(),
            'reopen_rate' => FactMeasures::reopenRate(),
        ];
    }

    public function filters(): array
    {
        return [
            'team' => new Filter('Team', 'f.team_id', labels: 'teams'),
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
        ];
    }
}

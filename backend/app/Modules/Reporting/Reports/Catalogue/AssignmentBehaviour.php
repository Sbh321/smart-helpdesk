<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-T08 Assignment behaviour: the assignment records of the period (automatic, manual, reassignment,
 * unassignment), how many tickets they touched, and the automatic runs that found no eligible agent.
 * The strategy and outcome come from the stored explanation.
 */
final class AssignmentBehaviour extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t08';
    }

    public function title(): string
    {
        return 'Assignment behaviour';
    }

    public function description(): string
    {
        return 'Automatic and manual assignments, reassignments per ticket and runs with no eligible agent.';
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
        return 'reason';
    }

    public function chart(): string
    {
        return 'stacked_bar';
    }

    /** The parts of the whole, which do not overlap (M4-09). */
    public function chartMeasures(): array
    {
        return ['automatic', 'manual', 'reassignments'];
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'a.ticket_id';
    }

    protected function source(): string
    {
        return 'ticket_assignments a';
    }

    protected function tenantColumn(): string
    {
        return 'a.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'a.created_at';
    }

    public function dimensions(): array
    {
        return [
            'reason' => new Dimension('Reason', 'a.reason', 'assignment_reason'),
            'day' => Dimension::day('a.created_at'),
            'week' => Dimension::week('a.created_at'),
            'team' => new Dimension('Team', 'a.team_id', 'teams'),
            'agent' => new Dimension('Agent', 'a.agent_profile_id', 'agents'),
            'strategy' => new Dimension('Strategy', "a.explanation ->> 'strategy'"),
            'outcome' => new Dimension('Outcome', "a.explanation ->> 'outcome'", 'assignment_outcome'),
        ];
    }

    public function measures(): array
    {
        return [
            'assignments' => Measure::count('Assignment records'),
            'automatic' => Measure::count('Automatic', "a.reason = 'auto'"),
            'manual' => Measure::count('Manual', "a.reason = 'manual'"),
            'reassignments' => Measure::count('Reassignments', "a.reason = 'reassign'"),
            'tickets' => new Measure('Tickets', 'count(DISTINCT a.ticket_id)'),
            'reassignments_per_ticket' => new Measure(
                'Reassignments per ticket',
                "round(count(*) FILTER (WHERE a.reason = 'reassign')::numeric / nullif(count(DISTINCT a.ticket_id), 0), 2)",
                'ratio',
            ),
            'no_eligible_agent' => Measure::count('No eligible agent', "a.explanation ->> 'outcome' = 'no_eligible_agent'"),
            'manual_overrides' => Measure::count('Manual choices of an ineligible agent', "a.explanation ->> 'manual_override' = 'true'"),
        ];
    }

    public function filters(): array
    {
        return [
            'reason' => new Filter('Reason', 'a.reason', labels: 'assignment_reason'),
            'team' => new Filter('Team', 'a.team_id', labels: 'teams'),
            'agent' => new Filter('Agent', 'a.agent_profile_id', labels: 'agents'),
        ];
    }
}

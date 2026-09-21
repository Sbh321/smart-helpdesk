<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-T07 Reopens and rework, over the tickets created in the period: the reopen rate is reopened
 * tickets ÷ tickets resolved at least once (both shown), plus tickets reopened more than once.
 */
final class ReopensAndRework extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t07';
    }

    public function title(): string
    {
        return 'Reopens and rework';
    }

    public function description(): string
    {
        return 'Reopen rate of the tickets created in the period, and the tickets reopened more than once.';
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
        return 'category';
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
            'category' => new Dimension('Category', 'f.category_id', 'categories'),
            'agent' => new Dimension('Agent', 'f.assigned_agent_id', 'agents'),
            'team' => new Dimension('Team', 'f.team_id', 'teams'),
            'priority' => new Dimension('Priority', 'f.priority_level', 'priority'),
            'week' => Dimension::week('f.created_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'resolved' => Measure::count('Resolved at least once', FactMeasures::EVER_RESOLVED),
            'reopened' => Measure::count('Reopened', 'f.reopen_count > 0'),
            'reopen_rate' => FactMeasures::reopenRate(),
            'reopened_more_than_once' => Measure::count('Reopened more than once', 'f.reopen_count > 1'),
            'reopens' => new Measure('Reopens', 'coalesce(sum(f.reopen_count), 0)'),
        ];
    }

    public function filters(): array
    {
        return [
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
            'team' => new Filter('Team', 'f.team_id', labels: 'teams'),
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-A05 Team comparison over the tickets created in the period, by their current team: volume, tickets
 * still open, median first response and resolution (business time) and SLA compliance.
 */
final class TeamComparison extends SqlReport
{
    public function key(): string
    {
        return 'rpt-a05';
    }

    public function title(): string
    {
        return 'Team comparison';
    }

    public function description(): string
    {
        return 'Volume, open tickets, response and resolution times and SLA compliance per team.';
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
        return 'team';
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
            'team' => new Dimension('Team', 'f.team_id', 'teams'),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => Measure::count('Tickets'),
            'open' => Measure::count('Still open', FactMeasures::OPEN),
            'first_response_median' => Measure::median('First response, median (business)', 'f.first_response_business_s'),
            'resolution_median' => Measure::median('Resolution, median (business)', 'f.resolution_business_s'),
            'sla_compliance' => FactMeasures::slaCompliance(),
            'reopen_rate' => FactMeasures::reopenRate(),
        ];
    }

    public function filters(): array
    {
        return [
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
        ];
    }
}

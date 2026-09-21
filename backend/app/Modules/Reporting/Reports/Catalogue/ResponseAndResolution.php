<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-T06 Response and resolution times of the tickets created in the period: median, 90th percentile
 * and average, in business time (the SLA calendar; wall-clock when the policy has none) and wall-clock.
 */
final class ResponseAndResolution extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t06';
    }

    public function title(): string
    {
        return 'Response and resolution times';
    }

    public function description(): string
    {
        return 'First response and resolution times of the tickets created in the period, in business and wall-clock time.';
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
        return 'priority';
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
        return 'report_ticket_facts f LEFT JOIN organizations o ON o.tenant_id = f.tenant_id AND o.id = f.organization_id';
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
            'priority' => new Dimension('Priority', 'f.priority_level', 'priority'),
            'week' => Dimension::week('f.created_at'),
            'month' => Dimension::month('f.created_at'),
            'team' => new Dimension('Team', 'f.team_id', 'teams'),
            'agent' => new Dimension('Agent', 'f.assigned_agent_id', 'agents'),
            'category' => new Dimension('Category', 'f.category_id', 'categories'),
            'tier' => new Dimension('Customer tier', "coalesce(o.tier, 'standard')"),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => Measure::count('Tickets'),
            'responded' => Measure::count('Responded', 'f.first_responded_at IS NOT NULL'),
            'first_response_median' => Measure::median('First response, median (business)', 'f.first_response_business_s'),
            'first_response_p90' => Measure::p90('First response, p90 (business)', 'f.first_response_business_s'),
            'first_response_median_wall' => Measure::median('First response, median (wall-clock)', 'f.first_response_wall_s'),
            'resolved' => Measure::count('Resolved', 'f.resolved_at IS NOT NULL'),
            'resolution_median' => Measure::median('Resolution, median (business)', 'f.resolution_business_s'),
            'resolution_p90' => Measure::p90('Resolution, p90 (business)', 'f.resolution_business_s'),
            'resolution_average' => Measure::average('Resolution, average (business)', 'f.resolution_business_s'),
            'resolution_median_wall' => Measure::median('Resolution, median (wall-clock)', 'f.resolution_wall_s'),
        ];
    }

    public function filters(): array
    {
        return [
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
            'team' => new Filter('Team', 'f.team_id', labels: 'teams'),
            'agent' => new Filter('Agent', 'f.assigned_agent_id', labels: 'agents'),
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
            'channel' => new Filter('Channel', 'f.channel', labels: 'channel'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-C03 Customer experience per organisation or tier, over the tickets created in the period: median
 * first response and resolution (business time), SLA compliance and the tickets still open.
 */
final class CustomerExperience extends SqlReport
{
    public function key(): string
    {
        return 'rpt-c03';
    }

    public function title(): string
    {
        return 'Customer experience';
    }

    public function description(): string
    {
        return 'Response and resolution times, SLA compliance and open tickets per organisation or tier.';
    }

    public function group(): string
    {
        return 'contacts';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['contacts.view', 'tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'tier';
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
            'tier' => new Dimension('Customer tier', "coalesce(o.tier, 'standard')", 'tier'),
            'organization' => new Dimension('Organisation', 'f.organization_id', 'organizations'),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => Measure::count('Tickets'),
            'first_response_median' => Measure::median('First response, median (business)', 'f.first_response_business_s'),
            'resolution_median' => Measure::median('Resolution, median (business)', 'f.resolution_business_s'),
            'sla_compliance' => FactMeasures::slaCompliance(),
            'open' => Measure::count('Still open', FactMeasures::OPEN),
        ];
    }

    public function filters(): array
    {
        return [
            'tier' => new Filter('Customer tier', "coalesce(o.tier, 'standard')", labels: 'tier'),
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
        ];
    }
}

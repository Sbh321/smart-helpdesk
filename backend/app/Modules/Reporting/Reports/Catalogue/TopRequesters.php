<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-C02 Top requesters: contacts (or organisations) ranked by the tickets they raised in the period,
 * with how many are still open, their reopen rate and the SLA breaches they experienced.
 */
final class TopRequesters extends SqlReport
{
    public function key(): string
    {
        return 'rpt-c02';
    }

    public function title(): string
    {
        return 'Top requesters';
    }

    public function description(): string
    {
        return 'Contacts or organisations by tickets raised in the period, most first.';
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
        return 'contact';
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

    protected function orderBy(): string
    {
        return 'count(*) DESC, 1';
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
            'contact' => new Dimension('Contact', 'f.contact_id', 'contacts'),
            'organization' => new Dimension('Organisation', 'f.organization_id', 'organizations'),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => Measure::count('Tickets'),
            'open' => Measure::count('Still open', FactMeasures::OPEN),
            'reopen_rate' => FactMeasures::reopenRate(),
            'breaches' => Measure::count('Tickets with an SLA breach', FactMeasures::BREACHED),
        ];
    }

    public function filters(): array
    {
        return [
            'organization' => new Filter('Organisation', 'f.organization_id', labels: 'organizations'),
        ];
    }
}

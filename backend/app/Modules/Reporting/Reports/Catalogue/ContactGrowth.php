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
 * RPT-C01 Contact growth: contacts created in the period, and active contacts (who raised at least one
 * ticket in the period). The organisation is the contact's current one for new contacts and the
 * ticket's for active ones.
 */
final class ContactGrowth extends SqlReport
{
    public function key(): string
    {
        return 'rpt-c01';
    }

    public function title(): string
    {
        return 'Contact growth';
    }

    public function description(): string
    {
        return 'New contacts and active contacts (who raised a ticket) in the period.';
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
        return 'week';
    }

    public function chart(): string
    {
        return 'line';
    }

    public function drillDownTo(): string
    {
        return 'contacts';
    }

    protected function recordKey(): string
    {
        return 'c.contact_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tenant_id, id AS contact_id, organization_id, created_at AS at, 'new' AS kind FROM contacts WHERE tenant_id = ?
                UNION ALL
                SELECT tenant_id, contact_id, organization_id, created_at, 'active' FROM tickets WHERE tenant_id = ?
            ) c
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId, $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'c.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'c.at';
    }

    public function dimensions(): array
    {
        return [
            'day' => Dimension::day('c.at'),
            'week' => Dimension::week('c.at'),
            'month' => Dimension::month('c.at'),
            'organization' => new Dimension('Organisation', 'c.organization_id', 'organizations'),
        ];
    }

    public function measures(): array
    {
        return [
            'new_contacts' => Measure::count('New contacts', "c.kind = 'new'"),
            'active_contacts' => new Measure('Active contacts', "count(DISTINCT c.contact_id) FILTER (WHERE c.kind = 'active')"),
        ];
    }

    public function filters(): array
    {
        return [
            'organization' => new Filter('Organisation', 'c.organization_id', labels: 'organizations'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-C04 Organisation changes, from change capture (`entity_changes`): tier changes of organisations,
 * and contacts that joined or left an organisation (a contact created in, moved into or out of, or
 * deleted from it).
 */
final class OrganisationChanges extends SqlReport
{
    public function key(): string
    {
        return 'rpt-c04';
    }

    public function title(): string
    {
        return 'Organisation changes';
    }

    public function description(): string
    {
        return 'Tier changes and contacts added to or removed from each organisation.';
    }

    public function group(): string
    {
        return 'contacts';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['contacts.view'];
    }

    public function defaultDimension(): string
    {
        return 'organization';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tenant_id, entity_id AS organization_id, occurred_at, 'tier_changed' AS kind
                FROM entity_changes
                WHERE tenant_id = ? AND entity_type = 'organizations' AND operation = 'update' AND changes -> 'tier' IS NOT NULL
                UNION ALL
                SELECT tenant_id, (changes -> 'organization_id' ->> 'new')::uuid, occurred_at, 'contact_added'
                FROM entity_changes
                WHERE tenant_id = ? AND entity_type = 'contacts' AND operation IN ('insert', 'update')
                  AND changes -> 'organization_id' ->> 'new' IS NOT NULL
                UNION ALL
                SELECT tenant_id, (changes -> 'organization_id' ->> 'old')::uuid, occurred_at, 'contact_removed'
                FROM entity_changes
                WHERE tenant_id = ? AND entity_type = 'contacts' AND operation IN ('update', 'delete')
                  AND changes -> 'organization_id' ->> 'old' IS NOT NULL
            ) c
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId, $parameters->tenantId, $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'c.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'c.occurred_at';
    }

    public function dimensions(): array
    {
        return [
            'organization' => new Dimension('Organisation', 'c.organization_id', 'organizations'),
            'day' => Dimension::day('c.occurred_at'),
            'week' => Dimension::week('c.occurred_at'),
            'month' => Dimension::month('c.occurred_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'tier_changes' => Measure::count('Tier changes', "c.kind = 'tier_changed'"),
            'contacts_added' => Measure::count('Contacts added', "c.kind = 'contact_added'"),
            'contacts_removed' => Measure::count('Contacts removed', "c.kind = 'contact_removed'"),
            'net_contacts' => new Measure(
                'Net change of contacts',
                "count(*) FILTER (WHERE c.kind = 'contact_added') - count(*) FILTER (WHERE c.kind = 'contact_removed')",
                'number',
            ),
        ];
    }
}

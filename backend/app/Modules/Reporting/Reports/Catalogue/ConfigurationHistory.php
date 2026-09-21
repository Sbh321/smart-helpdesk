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
 * RPT-G02 Configuration history, from change capture: changes to SLA policies and targets, business
 * calendars and holidays, workspace settings (automation weights, thresholds and strategy versions live
 * there), categories and skills, by entity type, date, actor or operation. The before/after values are
 * in each record's change log (`GET /v1/history/{type}/{id}`).
 */
final class ConfigurationHistory extends SqlReport
{
    public const array ENTITY_TYPES = ['sla_policies', 'sla_targets', 'business_calendars', 'calendar_holidays', 'tenant_settings', 'categories', 'skills'];

    public function key(): string
    {
        return 'rpt-g02';
    }

    public function title(): string
    {
        return 'Configuration history';
    }

    public function description(): string
    {
        return 'Changes to SLA policies, calendars, settings, categories and skills, by type, date and actor.';
    }

    public function group(): string
    {
        return 'administration';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['settings.manage'];
    }

    public function defaultDimension(): string
    {
        return 'entity_type';
    }

    protected function source(): string
    {
        $types = implode(', ', array_map(fn (string $type): string => "'{$type}'", self::ENTITY_TYPES));

        return "(SELECT * FROM entity_changes WHERE tenant_id = ? AND entity_type IN ({$types})) c";
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId];
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
            'entity_type' => new Dimension('Configuration', 'c.entity_type', 'entity_type'),
            'day' => Dimension::day('c.occurred_at'),
            'week' => Dimension::week('c.occurred_at'),
            'actor' => new Dimension('Actor', 'c.actor_id', 'users'),
            'operation' => new Dimension('Operation', 'c.operation', 'operation'),
        ];
    }

    public function measures(): array
    {
        return [
            'changes' => Measure::count('Changes'),
            'records' => new Measure('Records changed', 'count(DISTINCT c.entity_id)'),
            'created' => Measure::count('Created', "c.operation = 'insert'"),
            'updated' => Measure::count('Changed', "c.operation = 'update'"),
            'deleted' => Measure::count('Deleted', "c.operation = 'delete'"),
        ];
    }

    public function filters(): array
    {
        return [
            'entity_type' => new Filter('Configuration', 'c.entity_type', labels: 'entity_type'),
        ];
    }
}

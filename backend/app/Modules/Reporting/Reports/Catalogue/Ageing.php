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
 * RPT-T05 Ageing: the tickets open now (not resolved or closed), by age since creation. The period does
 * not apply (and there is no comparison): the report is always about the current backlog.
 */
final class Ageing extends SqlReport
{
    /** Age bucket keys sort in age order; DimensionLabels `age_bucket` names them. */
    private const string BUCKET = <<<'SQL'
        CASE WHEN a.age_s < 86400 THEN '1' WHEN a.age_s < 3 * 86400 THEN '2' WHEN a.age_s < 7 * 86400 THEN '3'
             WHEN a.age_s < 30 * 86400 THEN '4' ELSE '5' END
        SQL;

    public function key(): string
    {
        return 'rpt-t05';
    }

    public function title(): string
    {
        return 'Ageing';
    }

    public function description(): string
    {
        return 'Open tickets now by age since creation. The period does not apply.';
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
        return 'age_bucket';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'a.ticket_id';
    }

    public function periodApplies(): bool
    {
        return false;
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT t.tenant_id, t.id AS ticket_id, t.team_id, t.category_id, t.assigned_agent_id,
                       coalesce(t.priority_override_level, t.priority_level) AS priority_level,
                       greatest(0, extract(epoch FROM (?::timestamptz - t.created_at))) AS age_s
                FROM tickets t WHERE t.tenant_id = ? AND t.status NOT IN ('resolved', 'closed')
            ) a
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$this->now(), $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'a.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'a.age_s';
    }

    public function dimensions(): array
    {
        return [
            'age_bucket' => new Dimension('Age', self::BUCKET, 'age_bucket'),
            'priority' => new Dimension('Priority', 'a.priority_level', 'priority'),
            'team' => new Dimension('Team', 'a.team_id', 'teams'),
            'agent' => new Dimension('Agent', 'a.assigned_agent_id', 'agents'),
            'category' => new Dimension('Category', 'a.category_id', 'categories'),
        ];
    }

    public function measures(): array
    {
        return [
            'open' => Measure::count('Open tickets'),
            'oldest' => new Measure('Oldest ticket', 'max(a.age_s)', 'seconds'),
            'average_age' => Measure::average('Average age', 'a.age_s'),
        ];
    }

    public function filters(): array
    {
        return [
            'priority' => new Filter('Priority', 'a.priority_level', labels: 'priority'),
            'team' => new Filter('Team', 'a.team_id', labels: 'teams'),
            'age_bucket' => new Filter('Age', self::BUCKET, labels: 'age_bucket'),
        ];
    }
}

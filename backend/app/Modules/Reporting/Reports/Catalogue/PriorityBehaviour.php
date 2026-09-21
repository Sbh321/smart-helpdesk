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
 * RPT-T09 Priority behaviour of the tickets created in the period: the current score distribution,
 * manual overrides, and level changes, of which the ones the system made (re-scoring as a ticket waits,
 * i.e. ageing) are counted apart from the ones a person caused by editing impact or urgency.
 */
final class PriorityBehaviour extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t09';
    }

    public function title(): string
    {
        return 'Priority behaviour';
    }

    public function description(): string
    {
        return 'Priority scores, manual overrides and level changes (from ageing or edits) of the tickets created in the period.';
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
        return <<<'SQL'
            report_ticket_facts f
            JOIN tickets t ON t.tenant_id = f.tenant_id AND t.id = f.ticket_id
            LEFT JOIN organizations o ON o.tenant_id = f.tenant_id AND o.id = f.organization_id
            LEFT JOIN (
                SELECT ticket_id, count(*) AS changes, count(*) FILTER (WHERE actor_type = 'system') AS ageing_changes
                FROM ticket_events WHERE tenant_id = ? AND type = 'priority_changed' GROUP BY ticket_id
            ) pe ON pe.ticket_id = f.ticket_id
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId];
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
            'initial_priority' => new Dimension('Priority at creation', 'f.initial_priority_level', 'priority'),
            'score' => new Dimension('Score band', "lpad((least(floor(t.priority_score / 10), 9) * 10)::int::text, 2, '0') || '–' || lpad((least(floor(t.priority_score / 10), 9) * 10 + 9)::int::text, 2, '0')"),
            'category' => new Dimension('Category', 'f.category_id', 'categories'),
            'tier' => new Dimension('Customer tier', "coalesce(o.tier, 'standard')", 'tier'),
            'strategy' => new Dimension('Strategy', 'f.priority_strategy'),
        ];
    }

    public function measures(): array
    {
        return [
            'tickets' => Measure::count('Tickets'),
            'average_score' => Measure::average('Average score', 't.priority_score', 'number'),
            'median_score' => Measure::median('Median score', 't.priority_score', 'number'),
            'overridden' => Measure::count('Manually overridden', 'f.priority_overridden'),
            'override_rate' => Measure::rate('Override rate', 'f.priority_overridden', 'true'),
            'raised' => Measure::count('Raised since creation', 'f.priority_level < f.initial_priority_level'),
            'level_changes' => new Measure('Level changes', 'coalesce(sum(pe.changes), 0)'),
            'ageing_changes' => new Measure('Level changes from ageing', 'coalesce(sum(pe.ageing_changes), 0)'),
        ];
    }

    public function filters(): array
    {
        return [
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
            'overridden' => new Filter('Overridden', 'f.priority_overridden', 'boolean'),
        ];
    }
}

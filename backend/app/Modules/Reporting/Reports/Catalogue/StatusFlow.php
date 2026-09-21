<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-T04 Status flow: every status change in the period (status changes, reopens, assignments that
 * moved an open ticket, duplicates closed), counted as `from → to`. Rework is a move out of resolved or
 * closed back into work.
 */
final class StatusFlow extends SqlReport
{
    private const string REWORK = "t.from_status IN ('resolved', 'closed') AND t.to_status NOT IN ('resolved', 'closed')";

    public function key(): string
    {
        return 'rpt-t04';
    }

    public function title(): string
    {
        return 'Status flow';
    }

    public function description(): string
    {
        return 'Status transitions in the period, with their share of all transitions and the rework loops.';
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
        return 'transition';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 't.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tenant_id, ticket_id, created_at, old_values ->> 'status' AS from_status, new_values ->> 'status' AS to_status
                FROM ticket_events
                WHERE tenant_id = ? AND type IN ('status_changed', 'reopened', 'assigned', 'unassigned', 'duplicate_marked')
                  AND old_values ->> 'status' IS NOT NULL AND new_values ->> 'status' IS NOT NULL
                  AND old_values ->> 'status' <> new_values ->> 'status'
            ) t
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 't.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 't.created_at';
    }

    public function dimensions(): array
    {
        return [
            'transition' => new Dimension('Transition', "t.from_status || ' → ' || t.to_status", 'transition'),
            'from_status' => new Dimension('From status', 't.from_status', 'status'),
            'to_status' => new Dimension('To status', 't.to_status', 'status'),
            'day' => Dimension::day('t.created_at'),
            'week' => Dimension::week('t.created_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'transitions' => Measure::count('Transitions'),
            'share' => new Measure('Share of all transitions', 'round(100.0 * count(*) / nullif(sum(count(*)) OVER (), 0), 1)', 'percent'),
            'tickets' => new Measure('Tickets', 'count(DISTINCT t.ticket_id)'),
            'rework' => Measure::count('Rework loops (back from resolved or closed)', self::REWORK),
        ];
    }
}

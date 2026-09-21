<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-T01 Ticket volume: tickets created, resolved, closed and reopened in the period, and the net change
 * of the backlog (created + reopened − resolved − closed without a resolution). A flow is dated by its
 * event; the dimensions are the ticket's current values.
 */
final class TicketVolume extends SqlReport
{
    public function key(): string
    {
        return 'rpt-t01';
    }

    public function title(): string
    {
        return 'Ticket volume';
    }

    public function description(): string
    {
        return 'Tickets created, resolved, closed and reopened, and the net change of the backlog.';
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

    public function chart(): string
    {
        return 'line';
    }

    public function defaultDimension(): string
    {
        return 'day';
    }

    public function drillDownTo(): string
    {
        return 'tickets';
    }

    protected function recordKey(): string
    {
        return 'e.ticket_id';
    }

    protected function source(): string
    {
        return <<<'SQL'
            (
                SELECT tenant_id, ticket_id, created_at AS at, 'created' AS kind FROM report_ticket_facts
                UNION ALL
                SELECT tenant_id, ticket_id, created_at, CASE
                    WHEN type = 'reopened' THEN 'reopened'
                    WHEN type = 'duplicate_marked' THEN 'closed_unresolved'
                    WHEN new_values ->> 'status' = 'resolved' THEN 'resolved'
                    WHEN new_values ->> 'status' = 'closed' AND old_values ->> 'status' = 'resolved' THEN 'closed'
                    ELSE 'closed_unresolved' END
                FROM ticket_events
                WHERE type IN ('reopened', 'duplicate_marked')
                   OR (type = 'status_changed' AND new_values ->> 'status' IN ('resolved', 'closed'))
            ) e
            JOIN report_ticket_facts f ON f.tenant_id = e.tenant_id AND f.ticket_id = e.ticket_id
            SQL;
    }

    protected function tenantColumn(): string
    {
        return 'e.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'e.at';
    }

    public function dimensions(): array
    {
        return [
            'day' => Dimension::day('e.at'),
            'week' => Dimension::week('e.at'),
            'month' => Dimension::month('e.at'),
            'channel' => new Dimension('Channel', 'f.channel', 'channel'),
            'category' => new Dimension('Category', 'f.category_id', 'categories'),
            'priority' => new Dimension('Priority', 'f.priority_level', 'priority'),
            'organization' => new Dimension('Organisation', 'f.organization_id', 'organizations'),
            'team' => new Dimension('Team', 'f.team_id', 'teams'),
        ];
    }

    public function measures(): array
    {
        return [
            'created' => Measure::count('Created', "e.kind = 'created'"),
            'resolved' => Measure::count('Resolved', "e.kind = 'resolved'"),
            'closed' => Measure::count('Closed', "e.kind IN ('closed', 'closed_unresolved')"),
            'reopened' => Measure::count('Reopened', "e.kind = 'reopened'"),
            'net_change' => new Measure(
                'Net change of the backlog',
                "count(*) FILTER (WHERE e.kind IN ('created', 'reopened')) - count(*) FILTER (WHERE e.kind IN ('resolved', 'closed_unresolved'))",
                'number',
            ),
        ];
    }

    public function filters(): array
    {
        return [
            'channel' => new Filter('Channel', 'f.channel', labels: 'channel'),
            'category' => new Filter('Category', 'f.category_id', labels: 'categories'),
            'priority' => new Filter('Priority', 'f.priority_level', labels: 'priority'),
            'team' => new Filter('Team', 'f.team_id', labels: 'teams'),
            'organization' => new Filter('Organisation', 'f.organization_id', labels: 'organizations'),
        ];
    }
}

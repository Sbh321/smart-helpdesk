<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Exports;

use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Tickets\Queries\TicketListCriteria;

/**
 * What `report_exports.parameters` holds, so the job re-runs exactly what was asked for.
 *
 * A report export stores the validated report parameters with the period fixed as `from`/`to` local
 * dates (a `last_7d` export queued just before midnight still covers the week it was asked for) and
 * keeps the preset name as `period_name` for display. A ticket-list export stores the validated list
 * criteria: `filter` (name => values), `search`, `sort` (`[column, direction]` pairs) and
 * `explicit_sort`; `me` in the assignee filter is resolved against the requester when the job runs.
 */
final class ExportParameters
{
    /** The sortable columns of `GET /v1/tickets`; anything else in stored parameters is dropped. */
    private const array TICKET_SORTS = ['priority_score', 'priority_level', 'created_at', 'updated_at', 'number', 'status', 'sla_due_at'];

    /** @return array<string, mixed> */
    public static function forReport(ReportParameters $parameters): array
    {
        return [
            'from' => $parameters->from->setTimezone($parameters->timezone)->toDateString(),
            'to' => $parameters->to->setTimezone($parameters->timezone)->subDay()->toDateString(),
            'period_name' => $parameters->period,
            'group' => $parameters->dimension,
            'measures' => $parameters->measures,
            'filter' => $parameters->filters,
            'compare' => $parameters->compare,
        ];
    }

    /** @return array<string, mixed> */
    public static function forTickets(TicketListCriteria $criteria): array
    {
        return [
            'filter' => $criteria->filters,
            'search' => $criteria->search,
            'sort' => $criteria->sort,
            'explicit_sort' => $criteria->explicitSort,
        ];
    }

    /** @param array<string, mixed> $parameters */
    public static function ticketCriteria(array $parameters, string $userId): TicketListCriteria
    {
        $filters = [];
        foreach ((array) ($parameters['filter'] ?? []) as $name => $values) {
            $filters[(string) $name] = array_values(array_map('strval', (array) $values));
        }

        $sort = [];
        foreach ((array) ($parameters['sort'] ?? []) as $pair) {
            $pair = (array) $pair;
            $column = (string) ($pair[0] ?? '');
            if (in_array($column, self::TICKET_SORTS, true)) {
                $sort[] = [$column, ($pair[1] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
            }
        }

        $search = $parameters['search'] ?? null;

        return new TicketListCriteria(
            $filters,
            is_string($search) && $search !== '' ? $search : null,
            $sort === [] ? (new TicketListCriteria)->sort : $sort,
            (bool) ($parameters['explicit_sort'] ?? false),
            $userId,
        );
    }
}

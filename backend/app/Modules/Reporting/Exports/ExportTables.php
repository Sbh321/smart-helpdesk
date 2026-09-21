<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Exports;

use App\Modules\Reporting\Reports\ReportDefinition;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\ReportResult;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Queries\TicketListCriteria;
use App\Modules\Tickets\Queries\TicketListQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;

/**
 * The tables an export writes (docs/04-domain/reporting.md §Exports as built). A report export is the
 * report page's data table: one row per group, the measures that were asked for, and a `Total` row. A
 * ticket-list export is the list as filtered, searched and sorted in the SPA, one ticket per row, times
 * in the workspace time zone. Must run inside the workspace.
 */
final class ExportTables
{
    /** The largest ticket list an export writes; the request is refused above it. */
    public const int TICKET_ROW_CAP = 50000;

    private const array UNIT_SUFFIX = ['seconds' => ' (seconds)', 'percent' => ' (%)', 'bytes' => ' (bytes)'];

    private const array TICKET_HEADERS = [
        'Number', 'Title', 'Status', 'Priority', 'Impact', 'Urgency', 'Category', 'Team', 'Assignee',
        'Contact', 'Contact email', 'Organisation', 'Created', 'Updated', 'First response', 'Resolved',
        'SLA state', 'SLA due',
    ];

    public function report(ReportDefinition $report, ReportParameters $parameters, ReportResult $result): ExportTable
    {
        $measures = $report->measures();
        $headers = [$report->dimensions()[$parameters->dimension]->label];
        foreach ($parameters->measures as $key) {
            $headers[] = $measures[$key]->label.(self::UNIT_SUFFIX[$measures[$key]->unit] ?? '');
        }

        $rows = [];
        foreach ($result->rows as $row) {
            $rows[] = [$row['label'], ...array_map(fn (string $key): int|float|null => $row['values'][$key] ?? null, $parameters->measures)];
        }
        $rows[] = ['Total', ...array_map(fn (string $key): int|float|null => $result->totals[$key] ?? null, $parameters->measures)];

        return new ExportTable($report->title(), $headers, $rows, summaryRows: 1);
    }

    /** How many tickets the list holds; the export is refused above `TICKET_ROW_CAP`. */
    public function ticketCount(TicketListCriteria $criteria): int
    {
        return (new TicketListQuery($criteria))->query()->reorder()->count();
    }

    public function tickets(TicketListCriteria $criteria, string $timezone): ExportTable
    {
        return new ExportTable('Tickets', self::TICKET_HEADERS, $this->ticketRows($criteria, $timezone));
    }

    /** @return Generator<int, list<string|int|float|null>> */
    private function ticketRows(TicketListCriteria $criteria, string $timezone): Generator
    {
        $time = fn (?CarbonInterface $at): ?string => $at?->setTimezone($timezone)->format('Y-m-d H:i');

        $query = (new TicketListQuery($criteria))->query()
            ->with(['category:id,name', 'team:id,name', 'assignedAgent.user:id,name', 'contact:id,name,email', 'organization:id,name']);

        // Chunks of 1 000 in the list's own order (it ends with `id`, so chunks are stable).
        $written = 0;
        foreach ($query->lazy(1000) as $ticket) {
            /** @var Ticket $ticket */
            if (++$written > self::TICKET_ROW_CAP) {
                return;
            }
            $slaDue = $ticket->getAttribute('sla_due_at');
            yield [
                $ticket->number,
                $ticket->title,
                $ticket->status->value,
                $ticket->effectivePriority()->value,
                $ticket->impact,
                $ticket->urgency,
                $ticket->category?->name,
                $ticket->team?->name,
                $ticket->assignedAgent?->user?->name,
                $ticket->contact?->name,
                $ticket->contact?->email,
                $ticket->organization?->name,
                $time($ticket->created_at),
                $time($ticket->updated_at),
                $time($ticket->first_responded_at),
                $time($ticket->resolved_at),
                $ticket->getAttribute('sla_state'),
                is_string($slaDue) ? $time(CarbonImmutable::parse($slaDue)) : null,
            ];
        }
    }
}

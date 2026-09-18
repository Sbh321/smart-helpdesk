import { dataTableColumnHelper } from '@/components/shared/data-table'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { StatusBadge } from '@/components/shared/status-badge'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import type { Ticket } from '../api/ticket-queries'

const helper = dataTableColumnHelper<Ticket>()
const none = <span className="text-muted-foreground">{copy.contacts.none}</span>

/**
 * Ticket list columns. The ids of sortable columns are the API's sort fields: the Priority column sorts
 * by `priority_score` (the finer order behind the P1–P4 level it shows).
 */
export function ticketColumns(timeZone: string) {
  return helper.columns([
    helper.accessor('number', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: copy.tickets.columns.number, className: 'w-24 tabular-nums' },
      cell: (info) => (
        <span className="font-mono">{fill(copy.tickets.number, { number: info.getValue() })}</span>
      ),
    }),
    helper.accessor('title', {
      enableHiding: false,
      meta: { label: copy.tickets.columns.title, className: 'max-w-md truncate font-medium' },
    }),
    helper.accessor('status', {
      enableSorting: true,
      meta: { label: copy.tickets.columns.status },
      cell: (info) => <StatusBadge status={info.getValue()} />,
    }),
    helper.accessor('priority_level', {
      id: 'priority_score',
      enableSorting: true,
      meta: { label: copy.tickets.columns.priority },
      cell: (info) => <PriorityBadge level={info.getValue()} />,
    }),
    helper.accessor((ticket) => ticket.contact?.name ?? null, {
      id: 'contact',
      meta: { label: copy.tickets.columns.contact },
      cell: (info) => info.getValue() ?? none,
    }),
    helper.accessor((ticket) => ticket.category?.name ?? null, {
      id: 'category',
      meta: { label: copy.tickets.columns.category },
      cell: (info) => info.getValue() ?? none,
    }),
    helper.accessor('created_at', {
      enableSorting: true,
      meta: { label: copy.tickets.columns.createdAt, className: 'tabular-nums' },
      cell: (info) => formatInZone(info.getValue(), timeZone, 'd MMM yyyy, HH:mm'),
    }),
    helper.accessor('updated_at', {
      enableSorting: true,
      meta: { label: copy.tickets.columns.updatedAt, className: 'tabular-nums' },
      cell: (info) => formatInZone(info.getValue(), timeZone, 'd MMM yyyy, HH:mm'),
    }),
  ])
}

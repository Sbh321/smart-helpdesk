import { dataTableColumnHelper } from '@/components/shared/data-table'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { StatusBadge } from '@/components/shared/status-badge'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import type { Ticket } from '../api/ticket-queries'

const helper = dataTableColumnHelper<Ticket>()
const none = <span className="text-muted-foreground">{copy.contacts.none}</span>

/** Agent and Team names for the ids a ticket carries (`useDirectoryNames`). */
export interface TicketColumnNames {
  agentName: (id: string | null) => string | undefined
  teamName: (id: string | null) => string | undefined
}

/** Columns the list hides until the viewer turns them on in the column menu. */
export const TICKET_HIDDEN_COLUMNS = { team: false, sla_due_at: false }

/**
 * Ticket list columns. The ids of sortable columns are the API's sort fields: the Priority column sorts
 * by `priority_score` (the finer order behind the P1–P4 level it shows), SLA due by `sla_due_at`.
 */
export function ticketColumns(timeZone: string, names: TicketColumnNames) {
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
    helper.accessor('assigned_agent_id', {
      id: 'assignee',
      meta: { label: copy.tickets.columns.assignee },
      cell: (info) => {
        const id = info.getValue()
        if (id === null)
          return <span className="text-muted-foreground">{copy.tickets.columns.unassigned}</span>
        return names.agentName(id) ?? copy.tickets.columns.unknownAgent
      },
    }),
    helper.accessor('team_id', {
      id: 'team',
      meta: { label: copy.tickets.columns.team },
      cell: (info) => {
        const id = info.getValue()
        if (id === null) return <span className="text-muted-foreground">{copy.tickets.columns.noTeam}</span>
        return names.teamName(id) ?? copy.tickets.columns.unknownTeam
      },
    }),
    // The list response carries the latest resolution timer's due time and state (M2-11).
    helper.accessor((ticket) => ticket.sla_due_at ?? null, {
      id: 'sla_due_at',
      enableSorting: true,
      meta: { label: copy.tickets.columns.slaDue, className: 'tabular-nums' },
      cell: (info) => {
        const due = info.getValue()
        if (!due) return <span className="text-muted-foreground">{copy.contacts.none}</span>
        const state = info.row.original.sla_state
        const late = state === 'breached'
        return (
          <span className={late ? 'font-medium text-destructive' : undefined}>
            {formatInZone(due, timeZone, 'd MMM, HH:mm')}
            {state === 'warning' || late ? (
              <span className="ml-1 text-xs">
                ({late ? copy.tickets.columns.slaBreached : copy.tickets.columns.slaWarning})
              </span>
            ) : null}
          </span>
        )
      },
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

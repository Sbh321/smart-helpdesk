import { GlobeIcon, MailIcon, MonitorIcon, SproutIcon } from 'lucide-react'
import { dataTableColumnHelper } from '@/components/shared/data-table'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { SlaIndicator } from '@/components/shared/sla-indicator'
import { StatusBadge } from '@/components/shared/status-badge'
import { copy, fill } from '@/copy/en'
import { durationBetween, formatInZone } from '@/lib/datetime/format'
import type { Ticket } from '../api/ticket-queries'

const helper = dataTableColumnHelper<Ticket>()
const none = <span className="text-muted-foreground">{copy.contacts.none}</span>

/** Agent and Team names for the ids a ticket carries (`useDirectoryNames`). */
export interface TicketColumnNames {
  agentName: (id: string | null) => string | undefined
  teamName: (id: string | null) => string | undefined
}

/** Columns the list hides until the viewer turns them on in the column menu. */
export const TICKET_HIDDEN_COLUMNS = {
  team: false,
  category: false,
  contact: false,
  // Age (time since it arrived) decides triage; last activity and the exact timestamps are a click away.
  updated_at: false,
  sla_due_at: false,
}

/** How a ticket reached the workspace (`created_via`), as an icon with its label beside it. */
const CHANNEL_ICONS = { ui: MonitorIcon, email: MailIcon, api: GlobeIcon, seed: SproutIcon } as const
const channelLabels = copy.reports.fixedLabels.channel ?? {}

/**
 * Ticket list columns (roadmap M4-04). The row answers "what should I pick next": subject with its
 * requester, how it arrived, its state, priority, SLA health, who owns it and how old it is. Category,
 * team, contact and the exact timestamps stay one click away in the column menu, so the row does not
 * turn into a wall of badges (docs/06-design-system/page-patterns.md §List page).
 *
 * The ids of sortable columns are the API's sort fields: Priority sorts by `priority_score` (the finer
 * order behind the P1–P4 level it shows), SLA health by `sla_due_at`, Age by `created_at` and Last
 * activity by `updated_at`.
 */
export function ticketColumns(timeZone: string, names: TicketColumnNames, now: Date = new Date()) {
  const nowIso = now.toISOString()
  // "2 days", not "2 days ago": the column header says which instant it counts from, and the exact
  // timestamp is one hover away. The row has no width to spare.
  const ago = (iso: string) => durationBetween(iso, nowIso)

  return helper.columns([
    helper.accessor('number', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: copy.tickets.columns.number, className: 'w-20 tabular-nums' },
      cell: (info) => (
        <span className="font-mono text-muted-foreground">
          {fill(copy.tickets.number, { number: info.getValue() })}
        </span>
      ),
    }),
    helper.accessor('title', {
      enableHiding: false,
      meta: { label: copy.tickets.columns.title, className: 'max-w-56' },
      cell: (info) => {
        const ticket = info.row.original
        const requester = [ticket.contact?.name, ticket.organization?.name].filter(Boolean).join(' · ')
        return (
          <div className="min-w-0">
            <span className="block truncate font-medium">{info.getValue()}</span>
            {requester ? (
              <span className="block truncate text-muted-foreground text-xs">{requester}</span>
            ) : null}
          </div>
        )
      },
    }),
    helper.accessor('created_via', {
      id: 'channel',
      meta: { label: copy.tickets.columns.channel, className: 'w-16' },
      cell: (info) => {
        const channel = info.getValue()
        const Icon = CHANNEL_ICONS[channel as keyof typeof CHANNEL_ICONS] ?? MonitorIcon
        const label = channelLabels[channel] ?? channel
        // Icon plus an accessible name: the word would cost a quarter of the row's width, and the
        // column header already says what the icon means.
        return (
          <span className="flex items-center text-muted-foreground" title={label}>
            <Icon aria-hidden="true" className="size-4 shrink-0" />
            <span className="sr-only">{label}</span>
          </span>
        )
      },
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
    // The list response carries the latest resolution timer's due time and state (M2-11).
    helper.accessor((ticket) => ticket.sla_due_at ?? null, {
      id: 'sla',
      enableSorting: false,
      meta: { label: copy.tickets.columns.sla, className: 'w-32' },
      cell: (info) => (
        <SlaIndicator
          compact
          state={info.row.original.sla_state}
          dueAt={info.getValue()}
          timeZone={timeZone}
          now={now}
        />
      ),
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
    helper.accessor('created_at', {
      id: 'age',
      enableSorting: true,
      meta: { label: copy.tickets.columns.age, className: 'w-20 tabular-nums' },
      cell: (info) => <span title={formatInZone(info.getValue(), timeZone)}>{ago(info.getValue())}</span>,
    }),
    helper.accessor('updated_at', {
      enableSorting: true,
      meta: { label: copy.tickets.columns.lastActivity, className: 'w-24 tabular-nums' },
      cell: (info) => (
        <span className="text-muted-foreground" title={formatInZone(info.getValue(), timeZone)}>
          {ago(info.getValue())}
        </span>
      ),
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
    helper.accessor('team_id', {
      id: 'team',
      meta: { label: copy.tickets.columns.team },
      cell: (info) => {
        const id = info.getValue()
        if (id === null) return <span className="text-muted-foreground">{copy.tickets.columns.noTeam}</span>
        return names.teamName(id) ?? copy.tickets.columns.unknownTeam
      },
    }),
    helper.accessor((ticket) => ticket.sla_due_at ?? null, {
      id: 'sla_due_at',
      enableSorting: true,
      meta: { label: copy.tickets.columns.slaDue, className: 'tabular-nums' },
      cell: (info) => {
        const due = info.getValue()
        return due ? formatInZone(due, timeZone, 'd MMM, HH:mm') : none
      },
    }),
  ])
}

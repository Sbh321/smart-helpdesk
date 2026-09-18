import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { BackLink } from '@/components/shared/back-link'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { StatusBadge } from '@/components/shared/status-badge'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { type Ticket, type TicketEvent, ticketQueries } from '../api/ticket-queries'
import { CreateTicketDialog } from './create-ticket-dialog'
import { TicketList } from './ticket-list'

/** `/$workspace/tickets`: the list, with "New ticket" for `tickets.create`. */
export function TicketsScreen({ workspace }: { workspace: string }) {
  const allowed = useCan('tickets.view')
  const canCreate = useCan('tickets.create')
  return (
    <>
      <PageHeader
        title={copy.tickets.title}
        description={copy.tickets.description}
        actions={allowed && canCreate ? <CreateTicketDialog /> : null}
      />
      {allowed ? <TicketList workspace={workspace} /> : <ForbiddenState />}
    </>
  )
}

function level(value: number, labels: Record<number, string>): string {
  return labels[value] ?? String(value)
}

function TicketFacts({ ticket, timeZone }: { ticket: Ticket; timeZone: string }) {
  const rows: Array<[string, ReactNode]> = [
    [copy.tickets.columns.status, <StatusBadge key="status" status={ticket.status} />],
    [
      copy.tickets.columns.priority,
      <span key="priority" className="inline-flex items-center gap-2">
        <PriorityBadge level={ticket.priority_level} />
        <span className="text-muted-foreground tabular-nums">
          {fill(copy.tickets.detail.score, { score: ticket.priority_score.toFixed(1) })}
        </span>
      </span>,
    ],
    [
      copy.tickets.columns.contact,
      ticket.contact ? `${ticket.contact.name} <${ticket.contact.email}>` : copy.contacts.none,
    ],
    [copy.contacts.columns.organization, ticket.organization?.name ?? copy.contacts.none],
    [copy.tickets.columns.category, ticket.category?.name ?? copy.contacts.none],
    [copy.tickets.create.impactLabel, level(ticket.impact, copy.tickets.impact)],
    [copy.tickets.create.urgencyLabel, level(ticket.urgency, copy.tickets.urgency)],
    [copy.tickets.create.tagsLabel, ticket.tags?.map((tag) => tag.name).join(', ') || copy.contacts.none],
    [copy.tickets.columns.createdAt, formatInZone(ticket.created_at, timeZone)],
    [copy.tickets.detail.createdVia, ticket.created_via],
  ]
  return (
    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
      {rows.map(([term, value]) => (
        <div key={term} className="contents">
          <dt className="text-muted-foreground">{term}</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  )
}

function changes(event: TicketEvent): string[] {
  const keys = new Set([...Object.keys(event.old_values), ...Object.keys(event.new_values)])
  return [...keys].map((key) => {
    const before = event.old_values[key]
    const after = event.new_values[key]
    return before === undefined ? `${key}: ${String(after)}` : `${key}: ${String(before)} → ${String(after)}`
  })
}

function TicketHistory({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const history = useQuery({ ...ticketQueries.history(tenantId, ticketId), enabled: tenantId !== '' })
  if (history.isPending) {
    return <Skeleton className="h-16 w-full" />
  }
  if (history.isError) {
    return <ErrorState error={history.error} onRetry={() => void history.refetch()} />
  }
  if (history.data.length === 0) {
    return <p className="text-sm text-muted-foreground">{copy.tickets.detail.historyEmpty}</p>
  }
  return (
    <ol className="flex flex-col gap-3">
      {history.data.map((event) => (
        <li key={event.id} className="rounded-lg border border-border p-3 text-sm">
          <p className="font-medium">
            {fill(copy.tickets.detail.event, {
              type: event.type.replace(/_/g, ' '),
              actor: event.actor_type === 'system' ? copy.tickets.detail.system : copy.tickets.detail.user,
            })}
            <span className="ml-2 font-normal text-muted-foreground">
              {formatInZone(event.created_at, timeZone)}
            </span>
          </p>
          {changes(event).length > 0 ? (
            <ul className="mt-1 text-muted-foreground">
              {changes(event).map((change) => (
                <li key={change}>{change}</li>
              ))}
            </ul>
          ) : null}
          {event.note ? <p className="mt-1">{event.note}</p> : null}
        </li>
      ))}
    </ol>
  )
}

/**
 * `/$workspace/tickets/$ticketId`: a placeholder that shows the ticket's fields and its history. The
 * full ticket page (replies, assignment, transitions, SLA) is milestone 2.
 */
export function TicketScreen({ workspace, ticketId }: { workspace: string; ticketId: string }) {
  const allowed = useCan('tickets.view')
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const ticket = useQuery({
    ...ticketQueries.detail(tenantId, ticketId),
    enabled: allowed && tenantId !== '',
  })
  const back = <BackLink workspace={workspace} to="/$workspace/tickets" label={copy.tickets.detail.back} />

  if (!allowed) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.tickets.detailTitle} />
        <ForbiddenState />
      </>
    )
  }
  if (ticket.isPending) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.tickets.detailTitle} />
        <Skeleton className="h-40 w-full max-w-2xl" />
      </>
    )
  }
  if (ticket.isError) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.tickets.detailTitle} />
        {isApiError(ticket.error) && ticket.error.status === 404 ? (
          <NotFoundState action={back} />
        ) : (
          <ErrorState error={ticket.error} onRetry={() => void ticket.refetch()} />
        )}
      </>
    )
  }

  const current = ticket.data
  return (
    <>
      <PageHeader
        eyebrow={back}
        title={`${fill(copy.tickets.number, { number: current.number })} ${current.title}`}
        description={copy.tickets.detail.placeholder}
      />
      <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section aria-labelledby="ticket-details" className="flex flex-col gap-4">
          <h2 id="ticket-details" className="text-base font-semibold">
            {copy.tickets.detail.fields}
          </h2>
          <p className="text-sm whitespace-pre-wrap">{current.description}</p>
          <TicketFacts ticket={current} timeZone={timeZone} />
        </section>
        <section aria-labelledby="ticket-history" className="flex flex-col gap-4">
          <h2 id="ticket-history" className="text-base font-semibold">
            {copy.tickets.detail.history}
          </h2>
          <TicketHistory tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
        </section>
      </div>
    </>
  )
}

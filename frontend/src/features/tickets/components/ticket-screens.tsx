import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { type ReactNode, useEffect, useState } from 'react'
import { BackLink } from '@/components/shared/back-link'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { PriorityExplanation } from '@/components/shared/priority-explanation'
import { StatusBadge } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { copy, fill } from '@/copy/en'
import { useDirectoryNames } from '@/features/agents'
import { TicketSlaPanel } from '@/features/sla'
import { isApiError } from '@/lib/api/errors'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { type Ticket, type TicketEvent, ticketQueries } from '../api/ticket-queries'
import { CommentsPanel } from './comments-panel'
import { CreateTicketDialog } from './create-ticket-dialog'
import { TicketActions } from './ticket-actions'
import { TicketAttachmentsPanel } from './ticket-attachments-panel'
import { TicketDuplicatesPanel } from './ticket-duplicates-panel'
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
        actions={
          allowed && canCreate ? (
            <>
              <Button variant="outline" render={<Link to="/$workspace/tickets/new" params={{ workspace }} />}>
                {copy.tickets.create.fullPage}
              </Button>
              <CreateTicketDialog />
            </>
          ) : null
        }
      />
      {allowed ? <TicketList workspace={workspace} /> : <ForbiddenState />}
    </>
  )
}

function level(value: number, labels: Record<number, string>): string {
  return labels[value] ?? String(value)
}

function TicketFacts({ ticket, timeZone }: { ticket: Ticket; timeZone: string }) {
  // The ticket carries ids only; names come from the Agent directory (never show a UUID).
  const names = useDirectoryNames(ticket.team_id !== null || ticket.assigned_agent_id !== null)
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
    [
      copy.tickets.detail.organizationTier,
      ticket.organization
        ? ((copy.organizations.tier as Record<string, string>)[ticket.organization.tier] ??
          ticket.organization.tier)
        : copy.contacts.none,
    ],
    [
      copy.tickets.detail.team,
      ticket.team_id
        ? (names.teamName(ticket.team_id) ??
          (names.isLoading ? copy.settings.loading : copy.assignment.unknownTeam))
        : copy.tickets.detail.unassigned,
    ],
    [
      copy.tickets.detail.agent,
      ticket.assigned_agent_id
        ? (names.agentName(ticket.assigned_agent_id) ??
          (names.isLoading ? copy.settings.loading : copy.assignment.unknownAgent))
        : copy.tickets.detail.unassigned,
    ],
    [copy.tickets.columns.category, ticket.category?.name ?? copy.contacts.none],
    [copy.tickets.create.impactLabel, level(ticket.impact, copy.tickets.impact)],
    [copy.tickets.create.urgencyLabel, level(ticket.urgency, copy.tickets.urgency)],
    [copy.tickets.create.tagsLabel, ticket.tags?.map((tag) => tag.name).join(', ') || copy.contacts.none],
    [copy.tickets.columns.createdAt, formatInZone(ticket.created_at, timeZone)],
    [copy.tickets.columns.updatedAt, formatInZone(ticket.updated_at, timeZone)],
    [
      copy.tickets.detail.resolvedAt,
      ticket.resolved_at ? formatInZone(ticket.resolved_at, timeZone) : copy.contacts.none,
    ],
    [
      copy.tickets.detail.closedAt,
      ticket.closed_at ? formatInZone(ticket.closed_at, timeZone) : copy.contacts.none,
    ],
    [copy.tickets.detail.createdVia, ticket.created_via],
  ]
  return (
    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
      {rows.map(([term, value]) => (
        <div key={term} className="contents">
          <dt className="text-muted-foreground">{term}</dt>
          <dd className="min-w-0 break-words">{value}</dd>
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
  const history = useInfiniteQuery({ ...ticketQueries.history(tenantId, ticketId), enabled: tenantId !== '' })
  if (history.isPending) {
    return <Skeleton className="h-16 w-full" />
  }
  if (history.isError && !history.data) {
    return <ErrorState error={history.error} onRetry={() => void history.refetch()} />
  }
  const events = history.data?.pages.flatMap((page) => page.data) ?? []
  if (events.length === 0) {
    return <p className="text-sm text-muted-foreground">{copy.tickets.detail.historyEmpty}</p>
  }
  return (
    <div className="flex flex-col gap-3">
      <ol className="flex flex-col gap-3">
        {events.map((event) => (
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
      {history.isError ? (
        <ErrorState error={history.error} onRetry={() => void history.fetchNextPage()} />
      ) : null}
      {history.hasNextPage ? (
        <Button
          variant="outline"
          disabled={history.isFetchingNextPage}
          onClick={() => void history.fetchNextPage()}
        >
          {history.isFetchingNextPage ? copy.tickets.detail.loadingOlder : copy.tickets.detail.loadOlder}
        </Button>
      ) : null}
    </div>
  )
}

/** Ticket workspace; subsequent tasks connect comments, media, SLA and automation. */
export function TicketScreen({ workspace, ticketId }: { workspace: string; ticketId: string }) {
  const allowed = useCan('tickets.view')
  const canReply = useCan('tickets.update')
  const [activityTab, setActivityTab] = useState('timeline')
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const ticket = useQuery({
    ...ticketQueries.detail(tenantId, ticketId),
    enabled: allowed && tenantId !== '',
  })
  const back = <BackLink workspace={workspace} to="/$workspace/tickets" label={copy.tickets.detail.back} />

  useEffect(() => {
    if (!canReply) return
    const shortcut = (event: KeyboardEvent) => {
      if (
        event.key !== 'r' ||
        event.defaultPrevented ||
        event.ctrlKey ||
        event.metaKey ||
        event.altKey ||
        event.repeat
      )
        return
      if (
        event.target instanceof Element &&
        event.target.closest(
          'input, textarea, select, button, a, [contenteditable="true"], [role="dialog"], [role="combobox"]',
        )
      )
        return
      event.preventDefault()
      setActivityTab('comments')
      requestAnimationFrame(() => document.getElementById('comment-body')?.focus())
    }
    window.addEventListener('keydown', shortcut)
    return () => window.removeEventListener('keydown', shortcut)
  }, [canReply])

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
      />
      <div className="mb-6 flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-2" aria-live="polite">
          <StatusBadge status={current.status} />
          <PriorityBadge level={current.priority_level} />
          {current.priority_overridden ? (
            <span className="text-sm text-muted-foreground">{copy.tickets.detail.manual}</span>
          ) : null}
          <PriorityExplanation
            data={current.priority_explanation}
            reason={current.priority_override_reason}
          />
        </div>
        <TicketActions key={current.id} ticket={current} />
      </div>
      <div className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div className="min-w-0">
          <p className="text-sm whitespace-pre-wrap">{current.description}</p>
          <Tabs value={activityTab} onValueChange={setActivityTab} className="mt-6">
            <TabsList aria-label={copy.tickets.detail.tabs}>
              {(['timeline', 'comments', 'attachments', 'duplicates'] as const).map((tab) => (
                <TabsTrigger
                  key={tab}
                  value={tab}
                  {...(tab === 'comments' ? { 'aria-keyshortcuts': 'r' } : {})}
                >
                  {copy.tickets.detail[tab]}
                </TabsTrigger>
              ))}
            </TabsList>
            <TabsContent value="timeline">
              <section aria-labelledby="ticket-history" className="flex flex-col gap-4">
                <h2 id="ticket-history" className="text-base font-semibold">
                  {copy.tickets.detail.history}
                </h2>
                <TicketHistory tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
              </section>
            </TabsContent>
            <TabsContent value="comments">
              <CommentsPanel tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
            </TabsContent>
            <TabsContent value="attachments">
              <TicketAttachmentsPanel tenantId={tenantId} ticketId={current.id} />
            </TabsContent>
            <TabsContent value="duplicates">
              <TicketDuplicatesPanel tenantId={tenantId} workspace={workspace} ticket={current} />
            </TabsContent>
          </Tabs>
        </div>
        <aside className="flex min-w-0 flex-col gap-6">
          <section
            aria-labelledby="ticket-details"
            className="flex flex-col gap-4 rounded-lg border border-border p-4"
          >
            <h2 id="ticket-details" className="text-base font-semibold">
              {copy.tickets.detail.fields}
            </h2>
            <TicketFacts ticket={current} timeZone={timeZone} />
          </section>
          <section aria-labelledby="ticket-sla" className="rounded-lg border border-border p-4">
            <h2 id="ticket-sla" className="mb-2 text-base font-semibold">
              {copy.tickets.detail.sla}
            </h2>
            <TicketSlaPanel tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
          </section>
        </aside>
      </div>
    </>
  )
}

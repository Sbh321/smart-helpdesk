import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import {
  ArrowRightLeftIcon,
  CopyIcon,
  FlagIcon,
  type LucideIcon,
  MessageSquareIcon,
  PanelRightOpenIcon,
  PaperclipIcon,
  PencilIcon,
  PlusCircleIcon,
  TimerIcon,
  UserRoundIcon,
} from 'lucide-react'
import { type ReactNode, useEffect } from 'react'
import { BackLink } from '@/components/shared/back-link'
import type { DetailTab } from '@/components/shared/detail-tabs'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { PriorityExplanation } from '@/components/shared/priority-explanation'
import { RecordLayout } from '@/components/shared/record-layout'
import { SidePanelSection } from '@/components/shared/side-panel-section'
import { StatusBadge } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { copy, fill } from '@/copy/en'
import { useDirectoryNames } from '@/features/agents'
import { AssignmentReason } from '@/features/automation'
import { useRecordNames } from '@/features/reports'
import { TicketSlaPanel, TicketSlaSummary } from '@/features/sla'
import { isApiError } from '@/lib/api/errors'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import {
  attributeLabel,
  formatRecordValue,
  type NameLookup,
  type ReadableChange,
} from '@/lib/format/record-values'
import { useRecordView } from '@/lib/record-view'
import { useMediaQuery, WIDE_QUERY } from '@/lib/use-media-query'
import { type Ticket, type TicketEvent, ticketQueries } from '../api/ticket-queries'
import { groupTicketEvents, type TicketEventKind, ticketEventKind } from '../ticket-timeline'
import { CommentsPanel } from './comments-panel'
import { CreateTicketDialog } from './create-ticket-dialog'
import { TicketActions } from './ticket-actions'
import { TicketAttachmentsPanel } from './ticket-attachments-panel'
import { TicketDuplicatesPanel } from './ticket-duplicates-panel'
import { TicketList } from './ticket-list'
import { TicketDetailRealtime } from './ticket-realtime'

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

/** The groups of facts the context panel shows, one `SidePanelSection` each (roadmap M4-05). */
type FactGroup = 'requester' | 'properties' | 'assignment' | 'dates'

function TicketFacts({ ticket, timeZone, group }: { ticket: Ticket; timeZone: string; group: FactGroup }) {
  // The ticket carries ids only; names come from the Agent directory (never show a UUID).
  const names = useDirectoryNames(ticket.team_id !== null || ticket.assigned_agent_id !== null)
  const channels = copy.reports.fixedLabels.channel ?? {}
  const groups: Record<FactGroup, Array<[string, ReactNode]>> = {
    requester: [
      [
        copy.tickets.columns.contact,
        ticket.contact ? (
          <span key="contact" className="flex flex-col">
            <span>{ticket.contact.name}</span>
            <span className="break-all text-muted-foreground text-xs">{ticket.contact.email}</span>
          </span>
        ) : (
          copy.contacts.none
        ),
      ],
      [copy.contacts.columns.organization, ticket.organization?.name ?? copy.contacts.none],
      [
        copy.tickets.detail.organizationTier,
        ticket.organization
          ? ((copy.organizations.tier as Record<string, string>)[ticket.organization.tier] ??
            ticket.organization.tier)
          : copy.contacts.none,
      ],
    ],
    properties: [
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
      [copy.tickets.columns.category, ticket.category?.name ?? copy.contacts.none],
      [copy.tickets.create.impactLabel, level(ticket.impact, copy.tickets.impact)],
      [copy.tickets.create.urgencyLabel, level(ticket.urgency, copy.tickets.urgency)],
      [copy.tickets.create.tagsLabel, ticket.tags?.map((tag) => tag.name).join(', ') || copy.contacts.none],
      [copy.tickets.detail.createdVia, channels[ticket.created_via] ?? ticket.created_via],
    ],
    assignment: [
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
    ],
    dates: [
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
    ],
  }
  return (
    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 text-sm">
      {groups[group].map(([term, value]) => (
        <div key={term} className="contents">
          <dt className="text-muted-foreground">{term}</dt>
          <dd className="min-w-0 break-words">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

/**
 * What one event changed, in words (roadmap M4-03): the column's name, the value before it and the value
 * after, through the shared vocabulary the History tab and the audit log use. Own keys only, so a map
 * is never indexed through its prototype.
 */
function changes(event: TicketEvent, timeZone: string, names: NameLookup): ReadableChange[] {
  const oldValues: Record<string, unknown> = event.old_values ?? {}
  const newValues: Record<string, unknown> = event.new_values ?? {}
  const keys = [...new Set([...Object.keys(oldValues), ...Object.keys(newValues)])]
  const format = (values: Record<string, unknown>, key: string) =>
    formatRecordValue(values[key], timeZone, { attribute: key, names })

  return keys.map((key) => ({
    label: attributeLabel(key),
    from: Object.hasOwn(oldValues, key) ? format(oldValues, key) : null,
    to: Object.hasOwn(newValues, key) ? format(newValues, key) : copy.entity360.values.empty,
  }))
}

const EVENT_ICONS: Record<TicketEventKind, LucideIcon> = {
  created: PlusCircleIcon,
  status: ArrowRightLeftIcon,
  priority: FlagIcon,
  assignment: UserRoundIcon,
  comment: MessageSquareIcon,
  attachment: PaperclipIcon,
  sla: TimerIcon,
  duplicate: CopyIcon,
  edit: PencilIcon,
}

function eventType(event: TicketEvent): string {
  return event.type.replace(/_/g, ' ')
}

/**
 * The ticket timeline, newest first: consecutive events by the same actor within a few minutes form one
 * step (a creation with its priority and assignment), each event with an icon for its kind (M3-03).
 */
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
  // The same option queries the History tab uses, so an id in a change reads as the record's name.
  const names = useRecordNames()
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
        {groupTicketEvents(events).map((group) => {
          const actor = group.actorType === 'system' ? copy.tickets.detail.system : copy.tickets.detail.user
          const [newest] = group.events
          const oldest = group.events.at(-1) ?? newest
          const single = group.events.length === 1
          return (
            <li key={group.key} className="rounded-lg border border-border p-3 text-sm">
              <p className="font-medium">
                {single && newest
                  ? fill(copy.tickets.detail.event, { type: eventType(newest), actor })
                  : fill(copy.tickets.detail.eventGroup, { count: group.events.length, actor })}
                <span className="ml-2 font-normal text-muted-foreground">
                  {oldest ? formatInZone(oldest.created_at, timeZone) : null}
                </span>
              </p>
              <ul className="mt-1 flex flex-col gap-1">
                {group.events.map((event) => {
                  const Icon = EVENT_ICONS[ticketEventKind(event.type)]
                  const lines = changes(event, timeZone, names)
                  return (
                    <li key={event.id} className="flex gap-2">
                      <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                      <div className="min-w-0">
                        {single ? null : <p>{eventType(event)}</p>}
                        {lines.length > 0 ? (
                          <ul className="text-muted-foreground">
                            {lines.map((line) => (
                              <li key={line.label}>
                                {line.label}:{' '}
                                {line.from === null ? null : (
                                  <>
                                    <span className="line-through">{line.from}</span>{' '}
                                    <span aria-hidden="true">→</span>{' '}
                                  </>
                                )}
                                <span className="text-foreground">{line.to}</span>
                              </li>
                            ))}
                          </ul>
                        ) : null}
                        {event.note ? <p>{event.note}</p> : null}
                      </div>
                    </li>
                  )
                })}
              </ul>
            </li>
          )
        })}
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

/**
 * Ticket workspace; subsequent tasks connect comments, media, SLA and automation. The route adds the
 * Overview and History tabs (M3-21) through `tabs`.
 */
export function TicketScreen({
  workspace,
  ticketId,
  tabs = [],
}: {
  workspace: string
  ticketId: string
  tabs?: readonly DetailTab[]
}) {
  const allowed = useCan('tickets.view')
  const canReply = useCan('tickets.update')
  const canAssign = useCan('tickets.assign')
  const wide = useMediaQuery(WIDE_QUERY)
  // The open section is `?tab=` (M4-10), so leaving a historical view returns to History.
  const { tab: activityTab, setTab: setActivityTab } = useRecordView('comments')
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
  }, [canReply, setActivityTab])

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
  const contextSections = (
    <>
      <SidePanelSection title={copy.tickets.detail.requester} static>
        <TicketFacts ticket={current} timeZone={timeZone} group="requester" />
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.fields} defaultOpen>
        <TicketFacts ticket={current} timeZone={timeZone} group="properties" />
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.assignmentSection}>
        <TicketFacts ticket={current} timeZone={timeZone} group="assignment" />
        {canAssign ? (
          <AssignmentReason tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
        ) : null}
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.sla} defaultOpen>
        <TicketSlaPanel tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.attachments}>
        <TicketAttachmentsPanel tenantId={tenantId} ticketId={current.id} />
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.duplicates}>
        <TicketDuplicatesPanel tenantId={tenantId} workspace={workspace} ticket={current} />
      </SidePanelSection>
      <SidePanelSection title={copy.tickets.detail.dates}>
        <TicketFacts ticket={current} timeZone={timeZone} group="dates" />
      </SidePanelSection>
    </>
  )
  return (
    <RecordLayout
      eyebrow={back}
      kind={copy.entity360.entities.tickets}
      title={`${fill(copy.tickets.number, { number: current.number })} ${current.title}`}
      description={current.contact?.name ?? undefined}
      timeZone={timeZone}
      history={tabs.find((tab) => tab.value === 'history')?.content}
    >
      <TicketDetailRealtime tenantId={tenantId} ticketId={current.id} />
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
          {/* The timers whose clock matters now, each labelled Response or Resolution (M4-07). */}
          <TicketSlaSummary tenantId={tenantId} ticketId={current.id} timeZone={timeZone} />
        </div>
        <div className="flex flex-wrap items-start gap-2">
          <TicketActions key={current.id} ticket={current} />
          {wide ? null : (
            <Sheet>
              <SheetTrigger render={<Button variant="outline" />}>
                <PanelRightOpenIcon aria-hidden="true" />
                {copy.tickets.detail.context}
              </SheetTrigger>
              <SheetContent closeLabel={copy.tickets.detail.closeContext}>
                <SheetTitle>{copy.tickets.detail.context}</SheetTitle>
                <aside aria-label={copy.tickets.detail.context} className="-mx-4 border-border border-t">
                  {contextSections}
                </aside>
              </SheetContent>
            </Sheet>
          )}
        </div>
      </div>
      <div className="grid gap-8 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div className="min-w-0">
          <p className="text-sm whitespace-pre-wrap">{current.description}</p>
          <Tabs value={activityTab} onValueChange={setActivityTab} className="mt-6">
            <TabsList aria-label={copy.tickets.detail.tabs}>
              {(['comments', 'timeline'] as const).map((tab) => (
                <TabsTrigger
                  key={tab}
                  value={tab}
                  {...(tab === 'comments' ? { 'aria-keyshortcuts': 'r' } : {})}
                >
                  {copy.tickets.detail[tab]}
                </TabsTrigger>
              ))}
              {tabs.map((tab) => (
                <TabsTrigger key={tab.value} value={tab.value}>
                  {tab.label}
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
            {tabs.map((tab) => (
              <TabsContent key={tab.value} value={tab.value}>
                {tab.content}
              </TabsContent>
            ))}
          </Tabs>
        </div>
        {/* The agent's context, as sections that open one at a time (page-patterns.md §Record page):
            what is always needed stays open, the rest is a keystroke away. From 1280 px it is a column
            beside the conversation; narrower, a drawer opened from the actions row (M4-13). */}
        {wide ? (
          <aside
            aria-labelledby="ticket-context"
            className="min-w-0 self-start rounded-lg border border-border"
          >
            {/* Names the landmark and keeps the heading order h1 → h2 → section h3 (axe heading-order). */}
            <h2 id="ticket-context" className="sr-only">
              {copy.tickets.detail.context}
            </h2>
            {contextSections}
          </aside>
        ) : null}
      </div>
    </RecordLayout>
  )
}

import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { HistoryIcon } from 'lucide-react'
import { type FormEvent, useId, useState } from 'react'
import { SelectFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import {
  AUDIT_SUBJECT_TYPE,
  type AuditEntry,
  actionLabel,
  auditChanges,
  auditQueries,
} from '@/features/audit'
import { ticketQueries } from '@/features/tickets'
import { isApiError } from '@/lib/api/errors'
import type { components } from '@/lib/api/schema'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone, isoToZonedInput, zonedInputToIso } from '@/lib/datetime/format'
import { useRecordView } from '@/lib/record-view'
import { type EntityChange, entityQueries, HISTORY_TYPE, type OverviewEntity } from '../api/entity-queries'
import { actorLabel, attributeLabel, formatAttributeValue, type NameLookup } from '../entity-history'
import { useRecordNames } from './use-record-names'

const text = copy.entity360
type TicketEvent = components['schemas']['TicketEventResource']
type Source = 'all' | 'changes' | 'events' | 'audit'

interface FieldChange {
  attribute: string
  from: unknown
  to: unknown
  /** `insert` lists values set; `update` old → new; `delete` values cleared. */
  kind: 'set' | 'change' | 'clear'
}

interface TimelineItem {
  key: string
  at: string
  source: 'changes' | 'events' | 'audit'
  title: string
  actorType: string | null
  actorId: string | null
  /** The actor's name when the API sends one (audit entries). */
  actorName?: string | null
  fields: FieldChange[]
  note: string | null
}

/**
 * Columns every row has, and the optimistic-lock counter (`version`): a person never changes them, so
 * listing them, or marking them as a difference, says nothing about the record.
 */
const BOOKKEEPING = new Set(['id', 'tenant_id', 'updated_at', 'version'])

function fromChange(change: EntityChange): TimelineItem {
  const kind = change.operation === 'insert' ? 'set' : change.operation === 'delete' ? 'clear' : 'change'
  return {
    key: `change-${change.id}`,
    at: change.occurred_at,
    source: 'changes',
    title: text.timeline.operations[change.operation] ?? change.operation,
    actorType: change.actor_type,
    actorId: change.actor_id,
    fields: Object.entries(change.changes)
      .filter(([attribute]) => !BOOKKEEPING.has(attribute))
      .map(([attribute, values]) => ({
        attribute,
        from: values.old,
        to: values.new,
        kind,
      })),
    note: null,
  }
}

function fromEvent(event: TicketEvent): TimelineItem {
  const attributes = [...new Set([...Object.keys(event.old_values), ...Object.keys(event.new_values)])]
  return {
    key: `event-${event.id}`,
    at: event.created_at,
    source: 'events',
    title: fill(text.timeline.event, { type: event.type.replace(/_/g, ' ') }),
    actorType: event.actor_type,
    actorId: event.actor_id,
    fields: attributes.map((attribute) => ({
      attribute,
      from: event.old_values[attribute],
      to: event.new_values[attribute],
      kind: attribute in event.old_values ? 'change' : 'set',
    })),
    note: event.note,
  }
}

/** An audit entry about this record (M3-03): its action and the recorded old → new values. */
function fromAudit(entry: AuditEntry): TimelineItem {
  return {
    key: `audit-${entry.id}`,
    at: entry.created_at,
    source: 'audit',
    title: fill(copy.audit.historyEntry, { action: actionLabel(entry.action) }),
    actorType: entry.actor_type,
    actorId: entry.actor_id,
    actorName: entry.actor_name,
    fields: auditChanges(entry.changes).map((line) => ({
      attribute: line.field === '' ? 'value' : line.field,
      from: line.old,
      to: line.new,
      kind: line.kind === 'change' ? 'change' : 'set',
    })),
    note: null,
  }
}

/** One millisecond before an instant: the state just before a change was applied. */
function justBefore(iso: string): string {
  return new Date(Date.parse(iso) - 1).toISOString()
}

/** A structured value (JSON object or list): its old → new is not readable in a line. */
function isStructured(value: unknown): boolean {
  return typeof value === 'object' && value !== null
}

function FieldLine({ field, timeZone, names }: { field: FieldChange; timeZone: string; names: NameLookup }) {
  const value = (raw: unknown) => formatAttributeValue(field.attribute, raw, timeZone, names)
  return (
    <p>
      <span className="font-medium text-foreground">{attributeLabel(field.attribute)}</span>{' '}
      {field.kind === 'change' && isStructured(field.from) && isStructured(field.to) ? (
        text.timeline.updated
      ) : field.kind === 'set' ? (
        <>
          {text.timeline.setTo} <span className="text-foreground">{value(field.to)}</span>
        </>
      ) : field.kind === 'clear' ? (
        <>
          {text.timeline.cleared} <del className="text-foreground">{value(field.from)}</del>
        </>
      ) : (
        <>
          {text.timeline.from} <del className="text-foreground">{value(field.from)}</del> {text.timeline.to}{' '}
          <ins className="text-foreground no-underline">{value(field.to)}</ins>
        </>
      )}
    </p>
  )
}

function forbidden(error: unknown): boolean {
  return isApiError(error) && error.status === 403
}

/**
 * The as-of view: the record's recorded attributes at an instant picked in the workspace zone, and the
 * differences from now (`GET /v1/history/{type}/{id}/as-of`).
 */
function AsOfView({
  type,
  id,
  at,
  onAtChange,
  timeZone,
  names,
}: {
  type: string
  id: string
  at: string | undefined
  onAtChange: (at: string | undefined) => void
  timeZone: string
  names: NameLookup
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const inputId = useId()
  const headingId = useId()
  const [draft, setDraft] = useState(at ? isoToZonedInput(at, timeZone) : '')
  const [invalid, setInvalid] = useState(false)
  const [shownAt, setShownAt] = useState(at)
  if (at !== shownAt) {
    // A timeline button picked an instant: show it in the input too.
    setShownAt(at)
    setDraft(at ? isoToZonedInput(at, timeZone) : '')
  }
  const view = useQuery({
    ...entityQueries.asOf(tenantId, type, id, at ?? ''),
    enabled: tenantId !== '' && at !== undefined,
  })

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const iso = zonedInputToIso(draft, timeZone)
    setInvalid(iso === null)
    if (iso !== null) onAtChange(iso)
  }
  const date = at ? formatInZone(at, timeZone, 'd MMM yyyy, HH:mm:ss') : ''
  const value = (attribute: string, raw: unknown) => formatAttributeValue(attribute, raw, timeZone, names)
  const differences = Object.entries(view.data?.differences ?? {}).filter(
    ([attribute]) => !BOOKKEEPING.has(attribute),
  )

  return (
    <section
      id="entity-as-of"
      aria-labelledby={headingId}
      className="flex scroll-mt-4 flex-col gap-3 rounded-lg border border-border p-4"
    >
      <div>
        <h2 id={headingId} className="text-sm font-semibold">
          {text.asOf.title}
        </h2>
        <p className="text-sm text-muted-foreground">{fill(text.asOf.description, { timezone: timeZone })}</p>
      </div>
      <form className="flex flex-wrap items-end gap-2" onSubmit={submit} noValidate>
        <div className="flex flex-col gap-1">
          <Label htmlFor={inputId}>{text.asOf.label}</Label>
          <Input
            id={inputId}
            type="datetime-local"
            step={1}
            value={draft}
            aria-invalid={invalid || undefined}
            aria-describedby={invalid ? `${inputId}-error` : undefined}
            onChange={(event) => setDraft(event.target.value)}
            className="w-auto"
          />
        </div>
        <Button type="submit">{text.asOf.show}</Button>
        {at ? (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setInvalid(false)
              onAtChange(undefined)
            }}
          >
            {text.asOf.clear}
          </Button>
        ) : null}
      </form>
      {invalid ? (
        <p id={`${inputId}-error`} className="text-sm text-destructive">
          {text.asOf.invalid}
        </p>
      ) : null}
      {at === undefined ? null : view.isPending ? (
        <p className="text-sm text-muted-foreground" aria-busy="true">
          {text.asOf.loading}
        </p>
      ) : view.isError ? (
        forbidden(view.error) ? (
          <ForbiddenState description={text.timeline.forbidden} />
        ) : (
          <ErrorState error={view.error} title={text.asOf.failed} onRetry={() => void view.refetch()} />
        )
      ) : !view.data.exists ? (
        <p className="text-sm font-medium" role="status">
          {fill(text.asOf.didNotExist, { date })}
        </p>
      ) : (
        <div className="flex flex-col gap-3" role="status" aria-live="polite">
          <p className="text-sm">
            <span className="font-medium">{fill(text.asOf.stateAt, { date })}</span>{' '}
            <span className="text-muted-foreground">
              {view.data.versions_after === 1
                ? text.asOf.versionsAfterOne
                : fill(text.asOf.versionsAfter, { count: view.data.versions_after })}
            </span>
          </p>
          {differences.length === 0 ? (
            <p className="text-sm text-muted-foreground">{fill(text.asOf.unchanged, { date })}</p>
          ) : (
            <div className="rounded-md border border-border">
              <table className="w-full text-sm">
                <caption className="sr-only">{text.asOf.differences}</caption>
                <TableHeader>
                  <TableRow>
                    <TableHead scope="col">{text.asOf.attribute}</TableHead>
                    <TableHead scope="col">{text.asOf.thenLabel}</TableHead>
                    <TableHead scope="col">{text.asOf.nowLabel}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {differences.map(([attribute, difference]) => (
                    <TableRow key={attribute}>
                      <TableHead scope="row" className="font-medium whitespace-normal">
                        {attributeLabel(attribute)}
                      </TableHead>
                      <TableCell className="whitespace-normal break-words bg-warning/10 font-medium">
                        {value(attribute, difference.then)}
                      </TableCell>
                      <TableCell className="whitespace-normal break-words text-muted-foreground">
                        {value(attribute, difference.now)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </table>
            </div>
          )}
          {/* Every attribute as it was, open, with the ones that differ from now marked by an edge and
              a word as well as a tint (M4-10), so the historical state is read against the present. */}
          <section aria-labelledby={`${headingId}-attributes`} className="text-sm">
            <h3 id={`${headingId}-attributes`} className="font-medium">
              {text.asOf.attributes}
            </h3>
            <dl className="mt-2 grid grid-cols-[max-content_1fr] gap-x-6">
              {Object.entries(view.data.attributes ?? {})
                .filter(([attribute]) => !BOOKKEEPING.has(attribute))
                .map(([attribute, raw]) => {
                  const changed = differences.some(([key]) => key === attribute)
                  return (
                    <div
                      key={attribute}
                      data-changed={changed || undefined}
                      className="col-span-2 grid grid-cols-subgrid border-l-2 border-transparent py-1 pl-2 data-changed:border-warning data-changed:bg-warning/10"
                    >
                      <dt className="text-muted-foreground">{attributeLabel(attribute)}</dt>
                      <dd className="min-w-0 break-words">
                        {value(attribute, raw)}
                        {changed ? (
                          <span className="ml-2 text-xs font-medium text-muted-foreground">
                            {fill(text.asOf.changedSince, {
                              now: value(attribute, view.data.differences[attribute]?.now),
                            })}
                          </span>
                        ) : null}
                      </dd>
                    </div>
                  )
                })}
            </dl>
          </section>
        </div>
      )}
    </section>
  )
}

export interface EntityHistoryPanelProps {
  entity: OverviewEntity
  id: string
}

/**
 * The History tab (roadmap M3-21): a unified timeline, newest first, of the record's recorded changes
 * and, for tickets, its domain events, each with the actor and old → new values in words; and the
 * as-of view with the differences from now. Audit entries about the record join for viewers with
 * `audit.view` (M3-03); emails join with M3-19.
 */
export function EntityHistoryPanel({ entity, id }: EntityHistoryPanelProps) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const type = HISTORY_TYPE[entity]
  const withEvents = entity === 'tickets'
  const auditType = AUDIT_SUBJECT_TYPE[entity]
  const withAudit = useCan('audit.view') && auditType !== undefined
  const [source, setSource] = useState<Source>('all')
  // The instant lives in the URL (M4-10): choosing one turns the whole record page into its
  // historical view (RecordLayout), and "Back to now" there clears it.
  const { asOf, setAsOf } = useRecordView('details')
  const at = asOf ?? undefined
  const setAt = (next: string | undefined) => setAsOf(next ?? null)
  const names = useRecordNames()
  const changes = useInfiniteQuery({ ...entityQueries.changes(tenantId, type, id), enabled: tenantId !== '' })
  const events = useInfiniteQuery({
    ...ticketQueries.history(tenantId, id),
    enabled: tenantId !== '' && withEvents,
  })
  const audit = useInfiniteQuery({
    ...auditQueries.list(tenantId, { 'filter[subject_type]': auditType ?? '', 'filter[subject_id]': id }),
    enabled: tenantId !== '' && withAudit,
  })

  if (changes.isError && forbidden(changes.error)) {
    return <ForbiddenState description={text.timeline.forbidden} />
  }

  // Two cursor streams merged by time: an item is shown only when no stream with older pages could
  // still hold something newer, so "Load older" never inserts items above ones already read.
  const streams = [
    { query: changes, items: (changes.data?.pages ?? []).flatMap((page) => page.data.map(fromChange)) },
    ...(withEvents
      ? [{ query: events, items: (events.data?.pages ?? []).flatMap((page) => page.data.map(fromEvent)) }]
      : []),
    ...(withAudit
      ? [{ query: audit, items: (audit.data?.pages ?? []).flatMap((page) => page.data.map(fromAudit)) }]
      : []),
  ]
  const cutoff = Math.max(
    Number.NEGATIVE_INFINITY,
    ...streams
      .filter((stream) => stream.query.hasNextPage && stream.items.length > 0)
      .map((stream) => Math.min(...stream.items.map((item) => Date.parse(item.at)))),
  )
  const items = streams
    .flatMap((stream) => stream.items)
    .filter((item) => Date.parse(item.at) >= cutoff)
    .filter((item) => source === 'all' || item.source === source)
    .sort((a, b) => Date.parse(b.at) - Date.parse(a.at))
  const pending = changes.isPending || (withEvents && events.isPending) || (withAudit && audit.isPending)
  const failed = streams.find((stream) => stream.query.isError)?.query
  const more = streams.some((stream) => stream.query.hasNextPage)
  const fetchingMore = streams.some((stream) => stream.query.isFetchingNextPage)

  return (
    <div className="flex flex-col gap-6">
      <AsOfView type={type} id={id} at={at} onAtChange={setAt} timeZone={timeZone} names={names} />
      <section aria-labelledby="entity-history-heading" className="flex flex-col gap-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 id="entity-history-heading" className="text-sm font-semibold">
              {text.timeline.title}
            </h2>
            <p className="text-sm text-muted-foreground">{text.timeline.description}</p>
          </div>
          {withEvents || withAudit ? (
            <SelectFilter
              label={text.timeline.sources}
              value={source}
              defaultValue="all"
              onChange={(value) => setSource((value ?? 'all') as Source)}
              options={[
                { value: 'all', label: text.timeline.sourceAll },
                { value: 'changes', label: text.timeline.sourceChanges },
                ...(withEvents ? [{ value: 'events', label: text.timeline.sourceEvents }] : []),
                ...(withAudit ? [{ value: 'audit', label: text.timeline.sourceAudit }] : []),
              ]}
            />
          ) : null}
        </div>
        {pending ? (
          <div className="flex flex-col gap-2" aria-busy="true">
            <Skeleton className="h-14 w-full" />
            <Skeleton className="h-14 w-full" />
          </div>
        ) : items.length === 0 && !failed ? (
          <EmptyState icon={HistoryIcon} title={text.timeline.empty} />
        ) : (
          <ol aria-label={text.timeline.label} className="flex flex-col gap-3">
            {items.map((item) => (
              <li key={item.key} className="rounded-lg border border-border p-3 text-sm">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p>
                    <span className="font-medium">{item.title}</span>{' '}
                    <span className="text-muted-foreground">
                      {fill(text.timeline.by, {
                        actor:
                          item.actorName && item.actorId !== session?.user.id
                            ? item.actorName
                            : actorLabel(item.actorType, item.actorId, session?.user.id, names),
                      })}
                    </span>
                  </p>
                  <time dateTime={item.at} className="text-muted-foreground tabular-nums">
                    {formatInZone(item.at, timeZone, 'd MMM yyyy, HH:mm:ss')}
                  </time>
                </div>
                {item.fields.length > 0 ? (
                  <div className="mt-1 flex flex-col gap-0.5 text-muted-foreground">
                    {item.fields.map((field) => (
                      <FieldLine key={field.attribute} field={field} timeZone={timeZone} names={names} />
                    ))}
                  </div>
                ) : item.source === 'changes' ? (
                  <p className="mt-1 text-muted-foreground">{text.timeline.noFields}</p>
                ) : null}
                {item.note ? <p className="mt-1">{fill(text.timeline.note, { note: item.note })}</p> : null}
                {item.source === 'changes' ? (
                  <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="mt-1 h-auto p-0"
                    onClick={() => {
                      setAt(justBefore(item.at))
                      document.getElementById('entity-as-of')?.scrollIntoView({ block: 'start' })
                    }}
                  >
                    {text.asOf.useChange}
                  </Button>
                ) : null}
              </li>
            ))}
          </ol>
        )}
        {failed ? (
          <ErrorState
            error={failed.error}
            title={text.timeline.failed}
            onRetry={() => void failed.refetch()}
          />
        ) : null}
        {more ? (
          <Button
            variant="outline"
            disabled={fetchingMore}
            onClick={() => {
              for (const stream of streams) if (stream.query.hasNextPage) void stream.query.fetchNextPage()
            }}
          >
            {fetchingMore ? text.timeline.loadingOlder : text.timeline.loadOlder}
          </Button>
        ) : null}
      </section>
    </div>
  )
}

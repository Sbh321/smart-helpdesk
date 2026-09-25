import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { ShieldCheckIcon, XIcon } from 'lucide-react'
import { useMemo } from 'react'
import {
  DataTable,
  DateRangeFilter,
  dataTableColumnHelper,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { SettingsPage } from '@/components/shared/settings-page'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { userQueries } from '@/features/users'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { useListParams } from '@/lib/list-params'
import {
  AUDIT_ACTOR_TYPES,
  type AuditEntry,
  auditApiQuery,
  auditListSchema,
  auditQueries,
} from '../api/audit-queries'
import {
  actionLabel,
  auditActorLabel,
  auditChanges,
  auditSubjectLabel,
  auditValue,
  fieldLabel,
  shortId,
  subjectTypeLabel,
} from '../audit-format'

const text = copy.audit
const helper = dataTableColumnHelper<AuditEntry>()
const ANY = 'any'

const ACTION_OPTIONS: FilterOption[] = Object.entries(text.actions)
  .map(([value, label]) => ({ value, label }))
  .sort((a, b) => a.label.localeCompare(b.label))
const ACTOR_TYPE_OPTIONS: FilterOption[] = AUDIT_ACTOR_TYPES.map((type) => ({
  value: type,
  label: text.actorTypes[type] ?? type,
}))
const SUBJECT_TYPE_OPTIONS: FilterOption[] = [
  { value: ANY, label: text.filters.anyRecord },
  ...Object.keys(text.subjectTypes)
    .filter((type) => type !== 'tenant')
    .map((type) => ({ value: type, label: subjectTypeLabel(type) })),
]

/** The recorded changes, folded: a summary with the count, old → new per field when opened. */
export function AuditChanges({ entry, timeZone }: { entry: AuditEntry; timeZone: string }) {
  const lines = auditChanges(entry.changes)
  if (lines.length === 0) return <span className="text-muted-foreground">{text.changes.none}</span>
  const value = (raw: unknown) => auditValue(raw, timeZone)
  return (
    <details className="max-w-md text-sm">
      <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
        {lines.length === 1 ? text.changes.showOne : fill(text.changes.show, { count: lines.length })}
      </summary>
      <dl className="mt-2 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1">
        {lines.map((line) => (
          <div key={`${line.kind}-${line.field}`} className="contents">
            <dt className="font-medium">{fieldLabel(line.field)}</dt>
            <dd className="min-w-0 break-words whitespace-normal">
              {line.kind === 'change' ? (
                <>
                  <span className="sr-only">{text.changes.from} </span>
                  <del className="text-muted-foreground">{value(line.old)}</del>
                  <span aria-hidden="true"> → </span>
                  <span className="sr-only"> {text.changes.to} </span>
                  <ins className="no-underline">{value(line.new)}</ins>
                </>
              ) : (
                value(line.new)
              )}
            </dd>
          </div>
        ))}
      </dl>
      {entry.request_id ? (
        <p className="mt-2 font-mono text-xs text-muted-foreground">
          {fill(text.changes.request, { id: entry.request_id })}
        </p>
      ) : null}
    </details>
  )
}

function auditColumns(timeZone: string, currentUserId: string | undefined) {
  return helper.columns([
    helper.accessor('created_at', {
      enableHiding: false,
      meta: { label: text.columns.when, className: 'whitespace-nowrap tabular-nums' },
      cell: (info) => (
        <time dateTime={info.getValue()}>
          {formatInZone(info.getValue(), timeZone, 'd MMM yyyy, HH:mm:ss')}
        </time>
      ),
    }),
    helper.accessor('actor_type', {
      meta: { label: text.columns.actor },
      cell: (info) => {
        const entry = info.row.original
        const label = auditActorLabel(entry, currentUserId)
        const kind = text.actorTypes[entry.actor_type] ?? entry.actor_type
        return (
          <span className="flex flex-col">
            <span className="font-medium">{label}</span>
            {label === kind ? null : <span className="text-xs text-muted-foreground">{kind}</span>}
          </span>
        )
      },
    }),
    helper.accessor('action', {
      enableHiding: false,
      meta: { label: text.columns.action },
      cell: (info) => (
        <span className="flex flex-col">
          <span>{actionLabel(info.getValue())}</span>
          <span className="font-mono text-xs text-muted-foreground">{info.getValue()}</span>
        </span>
      ),
    }),
    helper.accessor('subject_type', {
      meta: { label: text.columns.subject },
      cell: (info) => {
        const entry = info.row.original
        const label = auditSubjectLabel(entry)
        if (label === null) return <span className="text-muted-foreground">—</span>
        return (
          <span className="flex flex-col">
            <span>{label}</span>
            {entry.subject_type && entry.subject_name ? (
              <span className="text-xs text-muted-foreground">{subjectTypeLabel(entry.subject_type)}</span>
            ) : null}
          </span>
        )
      },
    }),
    helper.accessor('changes', {
      enableHiding: false,
      meta: { label: text.columns.changes, className: 'whitespace-normal' },
      cell: (info) => <AuditChanges entry={info.row.original} timeZone={timeZone} />,
    }),
    helper.accessor('ip_address', {
      meta: { label: text.columns.origin, className: 'font-mono text-xs' },
      cell: (info) => info.getValue() ?? '—',
    }),
  ])
}

/**
 * Settings → Audit log (roadmap M3-03): the workspace's security audit entries, newest first, with
 * filters in the URL, readable actors and records, and the recorded changes folded per row
 * (`GET /v1/audit-logs`, a cursor feed: "Load older entries" instead of page numbers).
 */
export function AuditSettings() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const allowed = useCan('audit.view')
  const canListUsers = useCan('users.manage')
  const list = useListParams(auditListSchema)
  const query = auditApiQuery(list.apiQuery)
  const entries = useInfiniteQuery({
    ...auditQueries.list(tenantId, query),
    enabled: allowed && tenantId !== '',
  })
  const users = useQuery({
    ...userQueries.list(tenantId, { page: 1, per_page: 100, sort: 'name' }),
    enabled: allowed && canListUsers && tenantId !== '',
  })
  const columns = useMemo(() => auditColumns(timeZone, session?.user.id), [timeZone, session?.user.id])

  if (!allowed) return <ForbiddenState />

  const filters = list.params.filters
  const rows = entries.data?.pages.flatMap((page) => page.data)
  const userOptions: FilterOption[] = [
    { value: ANY, label: text.filters.anyone },
    ...(users.data?.data ?? []).map((user) => ({ value: user.id, label: user.name })),
  ]
  const record = filters.subject_id?.[0]

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={ShieldCheckIcon}
        title={text.noMatchesTitle}
        description={text.noMatchesBody}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState icon={ShieldCheckIcon} title={text.emptyTitle} description={text.emptyBody} />
    )

  return (
    <SettingsPage title={text.title} description={fill(text.intro, { timezone: timeZone })}>
      <DataTable
        id="settings-audit"
        label={text.tableLabel}
        columns={columns}
        data={rows}
        rowCount={undefined}
        state={{ page: 1, per_page: list.params.per_page, sort: list.params.sort }}
        onStateChange={list.update}
        getRowId={(entry) => entry.id}
        isFetching={entries.isFetching && !entries.isFetchingNextPage && rows !== undefined}
        error={entries.error}
        onRetry={() => void entries.refetch()}
        emptyState={emptyState}
        defaultColumnVisibility={{ ip_address: false }}
        toolbar={
          <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
            <MultiSelectFilter
              label={text.filters.action}
              options={ACTION_OPTIONS}
              value={filters.action ?? []}
              onChange={(value) => list.setFilter('action', value)}
            />
            <MultiSelectFilter
              label={text.filters.actorType}
              options={ACTOR_TYPE_OPTIONS}
              value={filters.actor_type ?? []}
              onChange={(value) => list.setFilter('actor_type', value)}
            />
            {canListUsers ? (
              <SelectFilter
                label={text.filters.actor}
                options={userOptions}
                value={filters.actor_id?.[0]}
                defaultValue={ANY}
                onChange={(value) => list.setFilter('actor_id', value ? [value] : undefined)}
              />
            ) : null}
            <SelectFilter
              label={text.filters.subjectType}
              options={SUBJECT_TYPE_OPTIONS}
              value={filters.subject_type?.[0]}
              defaultValue={ANY}
              onChange={(value) => list.setFilter('subject_type', value ? [value] : undefined)}
            />
            <DateRangeFilter
              label={text.filters.date}
              value={filters.created_between}
              onChange={(value) => list.setFilter('created_between', value)}
            />
            {record ? (
              <Badge variant="outline" className="h-8 gap-1 pr-1">
                {fill(text.filters.oneRecord, { id: shortId(record) })}
                <Hint label={text.filters.clearRecord}>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-xs"
                    aria-label={text.filters.clearRecord}
                    onClick={() => list.setFilter('subject_id', undefined)}
                  >
                    <XIcon aria-hidden="true" />
                  </Button>
                </Hint>
              </Badge>
            ) : null}
          </FilterBar>
        }
      />
      {entries.hasNextPage ? (
        <Button
          type="button"
          variant="outline"
          disabled={entries.isFetchingNextPage}
          onClick={() => void entries.fetchNextPage()}
        >
          {entries.isFetchingNextPage ? text.loadingOlder : text.loadOlder}
        </Button>
      ) : null}
    </SettingsPage>
  )
}

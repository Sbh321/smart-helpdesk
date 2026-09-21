import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import { ChartCard } from '@/components/shared/chart-card'
import { dataTableColumnHelper } from '@/components/shared/data-table'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { KpiTile } from '@/components/shared/kpi-tile'
import { PriorityBadge } from '@/components/shared/priority-badge'
import { StatusBadge } from '@/components/shared/status-badge'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { type EntityOverview, entityQueries, type OverviewEntity } from '../api/entity-queries'
import { type LifecycleInterval, lifecycleShares, type NameLookup } from '../entity-history'
import { formatDuration, formatMeasure } from '../format'
import { ChartTable } from './chart-table'
import { LocalTable } from './local-table'
import { type ChartRow, SeriesChart } from './series-chart'
import { useRecordNames } from './use-record-names'

const text = copy.entity360
const linkClass = 'font-medium underline-offset-4 hover:underline'

type MetricUnit = 'count' | 'seconds' | 'percent' | 'tier' | 'availability' | 'sla'

const TICKET_METRICS: Array<[string, MetricUnit]> = [
  ['tickets', 'count'],
  ['open_tickets', 'count'],
  ['breached', 'count'],
  ['sla_compliance', 'percent'],
  ['reopen_rate', 'percent'],
  ['first_response_median_seconds', 'seconds'],
  ['resolution_median_seconds', 'seconds'],
]
const PERFORMANCE: Array<[string, MetricUnit]> = [
  ['assigned_30d', 'count'],
  ['resolved_30d', 'count'],
  ['first_replies_30d', 'count'],
  ['resolution_median_seconds_30d', 'seconds'],
  ['sla_compliance_30d', 'percent'],
  ['reopen_rate_30d', 'percent'],
]

/** The metrics of each overview, in display order (backend `Overviews\EntityOverviews`). */
const METRICS: Record<OverviewEntity, Array<[string, MetricUnit]>> = {
  tickets: [
    ['first_response_seconds', 'seconds'],
    ['resolution_seconds', 'seconds'],
    ['pending_seconds', 'seconds'],
    ['unassigned_seconds', 'seconds'],
    ['reassignments', 'count'],
    ['reopens', 'count'],
    ['comments', 'count'],
    ['public_replies', 'count'],
    ['first_response_sla', 'sla'],
    ['resolution_sla', 'sla'],
  ],
  contacts: TICKET_METRICS,
  organizations: [['tier', 'tier'], ['contacts', 'count'], ...TICKET_METRICS],
  agents: [
    ['capacity', 'count'],
    ['availability', 'availability'],
    ['open_tickets', 'count'],
    ...PERFORMANCE,
  ],
  teams: PERFORMANCE,
  categories: TICKET_METRICS,
}

const AVAILABILITY: Record<string, string> = {
  available: copy.settings.availabilityControl.available,
  away: copy.settings.availabilityControl.away,
  offline: copy.settings.availabilityControl.offline,
}
const TIERS = copy.organizations.tier as Record<string, string>

function metricValue(value: number | string | null | undefined, unit: MetricUnit): string {
  if (value === null || value === undefined) return copy.reports.units.empty
  switch (unit) {
    case 'tier':
      return TIERS[String(value)] ?? String(value)
    case 'availability':
      return AVAILABILITY[String(value)] ?? String(value)
    case 'sla':
      return text.slaOutcome[String(value)] ?? String(value)
    default:
      return formatMeasure(typeof value === 'number' ? value : Number(value), unit)
  }
}

/** `related` and `trends` are typed per entity by the backend; these read them defensively. */
function list<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : []
}

type Named = {
  id: string | null
  name: string | null
}

function Section({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <section className="flex min-w-0 flex-col gap-2">
      <div>
        <h2 className="text-sm font-semibold">{title}</h2>
        {description ? <p className="text-xs text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  )
}

function RecordLink({
  workspace,
  entity,
  id,
  children,
}: {
  workspace: string
  entity: 'agents' | 'teams' | 'categories' | 'tickets' | 'organizations'
  id: string
  children: ReactNode
}) {
  switch (entity) {
    case 'agents':
      return (
        <Link to="/$workspace/agents/$agentId" params={{ workspace, agentId: id }} className={linkClass}>
          {children}
        </Link>
      )
    case 'teams':
      return (
        <Link to="/$workspace/teams/$teamId" params={{ workspace, teamId: id }} className={linkClass}>
          {children}
        </Link>
      )
    case 'categories':
      return (
        <Link
          to="/$workspace/categories/$categoryId"
          params={{ workspace, categoryId: id }}
          className={linkClass}
        >
          {children}
        </Link>
      )
    case 'tickets':
      return (
        <Link to="/$workspace/tickets/$ticketId" params={{ workspace, ticketId: id }} className={linkClass}>
          {children}
        </Link>
      )
    case 'organizations':
      return (
        <Link
          to="/$workspace/organizations/$organizationId"
          params={{ workspace, organizationId: id }}
          className={linkClass}
        >
          {children}
        </Link>
      )
  }
}

function NamedList({
  workspace,
  entity,
  items,
  detail,
}: {
  workspace: string
  entity: 'agents' | 'teams' | 'categories'
  items: Array<Named & { detail?: string }>
  detail?: (item: Named & { detail?: string }) => string | undefined
}) {
  if (items.length === 0) return <p className="text-sm text-muted-foreground">{text.empty}</p>
  return (
    <ul className="flex flex-wrap gap-2">
      {items.map((item) => (
        <li key={item.id ?? item.name ?? ''}>
          <Badge variant="secondary" className="gap-1">
            {item.id ? (
              <RecordLink workspace={workspace} entity={entity} id={item.id}>
                {item.name ?? copy.reports.none}
              </RecordLink>
            ) : (
              (item.name ?? copy.reports.none)
            )}
            {detail?.(item) ? <span className="text-muted-foreground">{detail(item)}</span> : null}
          </Badge>
        </li>
      ))}
    </ul>
  )
}

const STATUS_COLOUR: Record<string, string> = {
  open: 'var(--chart-1)',
  assigned: 'var(--chart-2)',
  in_progress: 'var(--chart-3)',
  pending: 'var(--chart-4)',
  resolved: 'var(--chart-5)',
  closed: 'var(--chart-6)',
}

/**
 * RPT-T12: one row per interval, a bar as long as its share of the ticket's life, so the trace reads
 * like a timeline; the interval table is the accessible alternative and carries the same numbers.
 */
function LifecycleTrace({
  intervals,
  timeZone,
  names,
}: {
  intervals: LifecycleInterval[]
  timeZone: string
  names: NameLookup
}) {
  const shares = lifecycleShares(intervals)
  const status = (value: string) => copy.reports.fixedLabels.status?.[value] ?? value
  const who = (interval: LifecycleInterval) =>
    [
      interval.assigned_agent_id ? (names.agent?.(interval.assigned_agent_id) ?? text.lifecycle.agent) : null,
      interval.team_id ? (names.team?.(interval.team_id) ?? text.lifecycle.team) : null,
    ]
      .filter(Boolean)
      .join(' · ')
  const table = (
    <div className="rounded-md border border-border">
      <table className="w-full text-sm">
        <caption className="sr-only">{text.lifecycle.tableLabel}</caption>
        <TableHeader>
          <TableRow>
            <TableHead scope="col">{text.lifecycle.seq}</TableHead>
            <TableHead scope="col">{text.lifecycle.status}</TableHead>
            <TableHead scope="col">{text.lifecycle.agent}</TableHead>
            <TableHead scope="col">{text.lifecycle.team}</TableHead>
            <TableHead scope="col">{text.lifecycle.priority}</TableHead>
            <TableHead scope="col">{text.lifecycle.startsAt}</TableHead>
            <TableHead scope="col">{text.lifecycle.endsAt}</TableHead>
            <TableHead scope="col" className="text-right">
              {text.lifecycle.wall}
            </TableHead>
            <TableHead scope="col" className="text-right">
              {text.lifecycle.business}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {intervals.map((interval) => (
            <TableRow key={interval.seq}>
              <TableHead scope="row" className="font-normal tabular-nums">
                {interval.seq}
              </TableHead>
              <TableCell>{status(interval.status)}</TableCell>
              <TableCell>
                {interval.assigned_agent_id
                  ? (names.agent?.(interval.assigned_agent_id) ?? copy.assignment.unknownAgent)
                  : copy.tickets.detail.unassigned}
              </TableCell>
              <TableCell>
                {interval.team_id
                  ? (names.team?.(interval.team_id) ?? copy.assignment.unknownTeam)
                  : copy.reports.none}
              </TableCell>
              <TableCell>{interval.priority_level ?? copy.reports.units.empty}</TableCell>
              <TableCell className="tabular-nums">{formatInZone(interval.starts_at, timeZone)}</TableCell>
              <TableCell className="tabular-nums">
                {interval.ends_at ? formatInZone(interval.ends_at, timeZone) : text.lifecycle.running}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {formatDuration(interval.wall_seconds)}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {interval.business_seconds === null
                  ? copy.reports.units.empty
                  : formatDuration(interval.business_seconds)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </table>
    </div>
  )
  return (
    <ChartCard
      title={text.lifecycle.title}
      description={text.lifecycle.description}
      headingLevel="h2"
      showTableLabel={copy.reports.showTable}
      hideTableLabel={copy.reports.hideTable}
      table={table}
    >
      {intervals.length === 0 ? (
        <p className="text-sm text-muted-foreground">{text.lifecycle.empty}</p>
      ) : (
        <ol aria-label={text.lifecycle.chartLabel} className="flex flex-col gap-1.5">
          {intervals.map((interval, index) => {
            const duration = formatDuration(interval.wall_seconds)
            return (
              <li
                key={interval.seq}
                className="grid grid-cols-[minmax(0,12rem)_minmax(0,1fr)_max-content] items-center gap-3 text-sm"
              >
                <span className="flex min-w-0 flex-col">
                  <span className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge status={interval.status} />
                    {interval.priority_level ? <PriorityBadge level={interval.priority_level} /> : null}
                  </span>
                  <span className="truncate text-xs text-muted-foreground">{who(interval)}</span>
                </span>
                <span className="h-3 rounded-sm bg-muted" aria-hidden="true">
                  <span
                    className="block h-3 rounded-sm"
                    style={{
                      width: `${Math.max(shares[index] ?? 0, 1)}%`,
                      backgroundColor: STATUS_COLOUR[interval.status] ?? 'var(--chart-6)',
                      opacity: interval.open ? 0.6 : 1,
                    }}
                  />
                </span>
                <span className="text-right tabular-nums">
                  <span className="sr-only">
                    {fill(text.lifecycle.segment, {
                      seq: interval.seq,
                      status: status(interval.status),
                      duration,
                    })}
                  </span>
                  <span aria-hidden="true">{duration}</span>
                  {interval.open ? (
                    <span className="block text-xs text-muted-foreground">{text.lifecycle.running}</span>
                  ) : null}
                </span>
              </li>
            )
          })}
        </ol>
      )}
    </ChartCard>
  )
}

type SlaTimer = {
  kind: string
  cycle: number
  state: string
  due_at: string
  met_at: string | null
  breached_at: string | null
}
type RecentTicket = {
  id: string
  number: number
  title: string
  status: string
  created_at: string
}

function TrendChart({
  name,
  rows,
  timeZone,
}: {
  name: string
  rows: Array<Record<string, unknown>>
  timeZone: string
}) {
  const dimension = name === 'tickets_per_week' ? 'week' : 'day'
  const measure = name === 'tickets_per_week' ? 'tickets' : 'backlog'
  const measures = [{ key: measure, label: text.trendMeasures[measure] ?? measure, unit: 'count' }]
  const title = text.trendTitles[name] ?? name
  const chartRows: ChartRow[] = rows.map((row) => {
    const key = String(row[dimension] ?? '')
    return {
      key,
      label: key ? formatInZone(`${key}T12:00:00Z`, timeZone, 'd MMM') : key,
      values: { [measure]: typeof row[measure] === 'number' ? (row[measure] as number) : null },
    }
  })
  return (
    <ChartCard
      title={title}
      headingLevel="h2"
      showTableLabel={copy.reports.showTable}
      hideTableLabel={copy.reports.hideTable}
      table={
        <ChartTable
          caption={title}
          dimensionLabel={text.trendDimension[dimension] ?? dimension}
          rows={chartRows}
          measures={measures}
        />
      }
    >
      {chartRows.length === 0 ? (
        <p className="text-sm text-muted-foreground">{text.empty}</p>
      ) : (
        <SeriesChart kind="line" rows={chartRows} measures={measures} label={title} />
      )}
    </ChartCard>
  )
}

function Related({
  overview,
  workspace,
  timeZone,
  names,
}: {
  overview: EntityOverview
  workspace: string
  timeZone: string
  names: NameLookup
}) {
  const related = overview.related
  switch (overview.entity) {
    case 'tickets': {
      const helper = dataTableColumnHelper<SlaTimer>()
      const when = (value: string | null) =>
        value ? formatInZone(value, timeZone) : copy.reports.units.empty
      return (
        <Section title={text.slaTimers.title}>
          <LocalTable
            id="overview-sla-timers"
            label={text.slaTimers.title}
            rows={list<SlaTimer>(related.sla_timers)}
            getRowId={(row) => `${row.kind}-${row.cycle}`}
            columns={helper.columns([
              helper.display({
                id: 'kind',
                meta: { label: text.slaTimers.kind },
                cell: ({ row }) => text.slaTimers.kinds[row.original.kind] ?? row.original.kind,
              }),
              helper.accessor('cycle', { meta: { label: text.slaTimers.cycle } }),
              helper.display({
                id: 'state',
                meta: { label: text.slaTimers.state },
                cell: ({ row }) => text.slaOutcome[row.original.state] ?? row.original.state,
              }),
              helper.display({
                id: 'due',
                meta: { label: text.slaTimers.dueAt },
                cell: ({ row }) => when(row.original.due_at),
              }),
              helper.display({
                id: 'met',
                meta: { label: text.slaTimers.metAt },
                cell: ({ row }) => when(row.original.met_at),
              }),
              helper.display({
                id: 'breached',
                meta: { label: text.slaTimers.breachedAt },
                cell: ({ row }) => when(row.original.breached_at),
              }),
            ])}
          />
        </Section>
      )
    }
    case 'contacts': {
      const organizationId = typeof related.organization_id === 'string' ? related.organization_id : null
      const helper = dataTableColumnHelper<RecentTicket>()
      return (
        <>
          <Section title={text.organization}>
            <p className="text-sm">
              {organizationId ? (
                <RecordLink workspace={workspace} entity="organizations" id={organizationId}>
                  {names.organization?.(organizationId) ?? text.organization}
                </RecordLink>
              ) : (
                text.noOrganization
              )}
            </p>
          </Section>
          <Section title={text.recentTickets}>
            <LocalTable
              id="overview-recent-tickets"
              label={text.recentTickets}
              rows={list<RecentTicket>(related.recent_tickets)}
              getRowId={(row) => row.id}
              columns={helper.columns([
                helper.display({
                  id: 'number',
                  meta: { label: text.columns.number },
                  cell: ({ row }) => (
                    <RecordLink workspace={workspace} entity="tickets" id={row.original.id}>
                      {fill(copy.tickets.number, { number: row.original.number })}
                    </RecordLink>
                  ),
                }),
                helper.accessor('title', { meta: { label: text.columns.title } }),
                helper.display({
                  id: 'status',
                  meta: { label: text.columns.status },
                  cell: ({ row }) => <StatusBadge status={row.original.status} />,
                }),
                helper.display({
                  id: 'created_at',
                  meta: { label: text.columns.createdAt },
                  cell: ({ row }) => formatInZone(row.original.created_at, timeZone),
                }),
              ])}
            />
          </Section>
        </>
      )
    }
    case 'organizations': {
      const helper = dataTableColumnHelper<Named & { tickets: number }>()
      const tiers = list<{ at: string; from: string | null; to: string | null }>(related.tier_history)
      const tier = (value: string | null) => (value ? (TIERS[value] ?? value) : copy.reports.none)
      return (
        <>
          <Section title={text.topCategories}>
            <LocalTable
              id="overview-top-categories"
              label={text.topCategories}
              rows={list<Named & { tickets: number }>(related.top_categories)}
              getRowId={(row) => row.id ?? 'none'}
              columns={helper.columns([
                helper.display({
                  id: 'name',
                  meta: { label: text.columns.name },
                  cell: ({ row }) =>
                    row.original.id ? (
                      <RecordLink workspace={workspace} entity="categories" id={row.original.id}>
                        {row.original.name ?? copy.reports.none}
                      </RecordLink>
                    ) : (
                      copy.reports.none
                    ),
                }),
                helper.accessor('tickets', {
                  meta: { label: text.columns.tickets, className: 'text-right' },
                }),
              ])}
            />
          </Section>
          <Section title={text.tierHistory}>
            {tiers.length === 0 ? (
              <p className="text-sm text-muted-foreground">{text.empty}</p>
            ) : (
              <ol className="flex flex-col gap-1 text-sm">
                {tiers.map((entry) => (
                  <li key={entry.at} className="flex flex-wrap gap-x-2">
                    <time dateTime={entry.at} className="text-muted-foreground tabular-nums">
                      {formatInZone(entry.at, timeZone)}
                    </time>
                    <span>
                      {entry.from === null
                        ? fill(text.tierFirst, { to: tier(entry.to) })
                        : fill(text.tierChange, { from: tier(entry.from), to: tier(entry.to) })}
                    </span>
                  </li>
                ))}
              </ol>
            )}
          </Section>
        </>
      )
    }
    case 'agents':
      return (
        <>
          <Section title={text.skills}>
            <NamedList
              workspace={workspace}
              entity="categories"
              items={list<Named & { level: number }>(related.skills).map((skill) => ({
                id: null,
                name: skill.name,
                detail: fill(text.skillLevel, { level: skill.level }),
              }))}
              detail={(item) => item.detail}
            />
          </Section>
          <Section title={text.teams}>
            <NamedList workspace={workspace} entity="teams" items={list<Named>(related.teams)} />
          </Section>
        </>
      )
    case 'teams': {
      const helper = dataTableColumnHelper<Named & { joined_at: string }>()
      return (
        <Section title={text.members}>
          <LocalTable
            id="overview-members"
            label={text.members}
            rows={list<Named & { joined_at: string }>(related.members)}
            getRowId={(row) => row.id ?? ''}
            columns={helper.columns([
              helper.display({
                id: 'name',
                meta: { label: text.columns.name },
                cell: ({ row }) =>
                  row.original.id ? (
                    <RecordLink workspace={workspace} entity="agents" id={row.original.id}>
                      {row.original.name}
                    </RecordLink>
                  ) : (
                    row.original.name
                  ),
              }),
              helper.display({
                id: 'joined_at',
                meta: { label: text.columns.joinedAt },
                cell: ({ row }) => formatInZone(row.original.joined_at, timeZone, 'd MMM yyyy'),
              }),
            ])}
          />
        </Section>
      )
    }
    case 'categories': {
      const helper = dataTableColumnHelper<Named & { resolved: number }>()
      return (
        <>
          <Section title={text.requiredSkills}>
            <NamedList
              workspace={workspace}
              entity="categories"
              items={list<Named>(related.required_skills).map((skill) => ({ id: null, name: skill.name }))}
            />
          </Section>
          <Section title={text.topAgents}>
            <LocalTable
              id="overview-top-agents"
              label={text.topAgents}
              rows={list<Named & { resolved: number }>(related.top_agents)}
              getRowId={(row) => row.id ?? ''}
              columns={helper.columns([
                helper.display({
                  id: 'name',
                  meta: { label: text.columns.name },
                  cell: ({ row }) =>
                    row.original.id ? (
                      <RecordLink workspace={workspace} entity="agents" id={row.original.id}>
                        {row.original.name}
                      </RecordLink>
                    ) : (
                      row.original.name
                    ),
                }),
                helper.accessor('resolved', {
                  meta: { label: text.columns.resolved, className: 'text-right' },
                }),
              ])}
            />
          </Section>
        </>
      )
    }
    default:
      return null
  }
}

export interface EntityOverviewPanelProps {
  entity: OverviewEntity
  id: string
  workspace: string
}

/**
 * The Overview tab (roadmap M3-21, docs/04-domain/reporting.md §Entity 360): key figures as KPI tiles,
 * related records and trends of one record from `GET /v1/{entity}/{id}/overview`. Loaded on demand
 * (it brings the chart library).
 */
export default function EntityOverviewPanel({ entity, id, workspace }: EntityOverviewPanelProps) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const names = useRecordNames()
  const overview = useQuery({ ...entityQueries.overview(tenantId, entity, id), enabled: tenantId !== '' })

  if (overview.isPending) {
    return (
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-busy="true">
        {[0, 1, 2, 3].map((index) => (
          <Skeleton key={index} className="h-24 w-full" />
        ))}
      </div>
    )
  }
  if (overview.isError) {
    return isApiError(overview.error) && overview.error.status === 403 ? (
      <ForbiddenState description={text.overviewForbidden} />
    ) : (
      <ErrorState
        error={overview.error}
        title={text.overviewFailed}
        onRetry={() => void overview.refetch()}
      />
    )
  }

  const data = overview.data
  const metrics = METRICS[entity]
  const windowed = entity === 'agents' || entity === 'teams'
  return (
    <div className="flex flex-col gap-6">
      <section aria-labelledby="overview-metrics" className="flex flex-col gap-3">
        <div>
          <h2 id="overview-metrics" className="text-sm font-semibold">
            {text.keyMetrics}
          </h2>
          {entity !== 'tickets' ? (
            <p className="text-xs text-muted-foreground">{windowed ? text.last30Days : text.allTime}</p>
          ) : null}
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {metrics.map(([key, unit]) => (
            <KpiTile
              key={key}
              headingLevel="h3"
              label={text.metrics[key] ?? key}
              value={metricValue(data.metrics[key], unit)}
            />
          ))}
        </div>
      </section>
      {entity === 'tickets' ? (
        <LifecycleTrace
          intervals={list<LifecycleInterval>(data.trends.lifecycle)}
          timeZone={timeZone}
          names={names}
        />
      ) : null}
      <div className="flex flex-col gap-4">
        <Related overview={data} workspace={workspace} timeZone={timeZone} names={names} />
      </div>
      {Object.entries(data.trends)
        .filter(([name]) => name !== 'lifecycle')
        .map(([name, rows]) => (
          <TrendChart key={name} name={name} rows={rows} timeZone={timeZone} />
        ))}
    </div>
  )
}

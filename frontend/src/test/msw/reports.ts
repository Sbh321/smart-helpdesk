import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import {
  AGENT_FIXTURES,
  CATEGORY_FIXTURES,
  CONTACT_FIXTURES,
  db,
  ORGANIZATION_FIXTURES,
  TEAM_FIXTURES,
} from './data'
import { type ApiResponseBody, apiUrl, problem } from './handlers'
import { validationFailed } from './list'
import { REPORT_DEFINITIONS } from './report-definitions'

type ReportDefinition = components['schemas']['ReportDefinitionResource']
type ReportRun = components['schemas']['ReportRunResource']
type Row = ReportRun['rows'][number]
type RunInput = {
  period?: string
  from?: string
  to?: string
  group?: string
  measures?: string
  filter?: Record<string, string>
  compare?: boolean | string
}

/** "Today" of the fixtures, in the workspace zone. */
export const REPORT_TODAY = '2026-09-21'
const PERIODS: Record<string, number> = {
  today: 1,
  yesterday: 1,
  last_7d: 7,
  last_30d: 30,
  last_90d: 90,
  this_month: 21,
  last_month: 31,
  this_year: 264,
}

/** Deterministic pseudo-random 0..1 from a string, so the same parameters give the same numbers. */
function noise(seed: string): number {
  let hash = 2166136261
  for (const char of seed) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619)
  return ((hash >>> 0) % 1000) / 1000
}

function addDays(date: string, days: number): string {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

function periodDays(input: RunInput): { from: string; days: number } {
  if (input.from && input.to) {
    const days = Math.round((Date.parse(input.to) - Date.parse(input.from)) / 86_400_000) + 1
    return { from: input.from, days: Math.max(1, Math.min(days, 120)) }
  }
  const days = Math.min(PERIODS[input.period ?? 'last_30d'] ?? 30, 120)
  return { from: addDays(REPORT_TODAY, 1 - days), days }
}

const FIXED_KEYS: Record<string, [string, string][]> = {
  priority: [
    ['P1', 'P1 Critical'],
    ['P2', 'P2 High'],
    ['P3', 'P3 Normal'],
    ['P4', 'P4 Low'],
  ],
  status: [
    ['open', 'Open'],
    ['assigned', 'Assigned'],
    ['in_progress', 'In progress'],
    ['pending', 'Pending'],
    ['resolved', 'Resolved'],
    ['closed', 'Closed'],
  ],
  channel: [
    ['ui', 'Agent (UI)'],
    ['api', 'API'],
    ['email', 'Email'],
  ],
  age_bucket: [
    ['1', 'Under 1 day'],
    ['2', '1–3 days'],
    ['3', '3–7 days'],
    ['4', '7–30 days'],
    ['5', 'Over 30 days'],
  ],
  tier: [
    ['standard', 'Standard'],
    ['premium', 'Premium'],
    ['enterprise', 'Enterprise'],
  ],
  kind: [
    ['first_response', 'First response'],
    ['resolution', 'Resolution'],
  ],
}
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

/** The rows' keys and labels for a dimension, as the API would label them. */
function dimensionKeys(group: string, input: RunInput): [string, string][] {
  const fixed = FIXED_KEYS[group]
  if (fixed) return fixed
  const { from, days } = periodDays(input)
  switch (group) {
    case 'day':
      return Array.from({ length: days }, (_, index) => {
        const day = addDays(from, index)
        return [day, day]
      })
    case 'week':
      return Array.from({ length: Math.ceil(days / 7) }, (_, index) => {
        const week = addDays(from, index * 7)
        return [week, week]
      })
    case 'month':
      return [[REPORT_TODAY.slice(0, 7), REPORT_TODAY.slice(0, 7)]]
    case 'agent':
      return AGENT_FIXTURES.map((agent) => [agent.id, agent.user.name])
    case 'team':
      return TEAM_FIXTURES.map((team) => [team.id, team.name])
    case 'category':
      return CATEGORY_FIXTURES.map((category) => [category.id, category.name])
    case 'organization':
      return ORGANIZATION_FIXTURES.map((organization) => [organization.id, organization.name])
    case 'contact':
      return CONTACT_FIXTURES.slice(0, 8).map((contact) => [contact.id, contact.name])
    case 'weekday':
      return WEEKDAYS.map((name, index) => [String(index + 1), name])
    case 'hour':
      return Array.from({ length: 24 }, (_, hour) => {
        const key = String(hour).padStart(2, '0')
        return [key, key]
      })
    case 'weekday_hour':
      return WEEKDAYS.flatMap((name, day) =>
        Array.from({ length: 24 }, (_, hour): [string, string] => {
          const key = `${day + 1}-${String(hour).padStart(2, '0')}`
          return [key, `${name} ${String(hour).padStart(2, '0')}:00`]
        }),
      )
    default:
      return [['-', 'None']]
  }
}

/** A plausible value for a measure unit. */
function value(unit: string, seed: string): number {
  const random = noise(seed)
  switch (unit) {
    case 'seconds':
      return Math.round(900 + random * 30_000)
    case 'percent':
      return Math.round((70 + random * 30) * 10) / 10
    case 'ratio':
      return Math.round(random * 100) / 100
    case 'bytes':
      return Math.round(random * 5_000_000)
    case 'number':
      return Math.round(random * 200) / 10
    default:
      return Math.round(random * 12)
  }
}

/** Everything the requests of the last test asked for, so tests can assert the API parameters. */
export const reportRequests: { run: { report: string; body: RunInput }[]; records: URLSearchParams[] } = {
  run: [],
  records: [],
}

export function resetReportRequests(): void {
  reportRequests.run.length = 0
  reportRequests.records.length = 0
}

/** A run like the backend's: one row per dimension value, totals, previous totals when compared. */
export function runReport(definition: ReportDefinition, input: RunInput): ReportRun | Response {
  const group = input.group ?? definition.default_dimension
  if (!definition.dimensions.some((dimension) => dimension.key === group)) {
    return validationFailed({
      group: [`Group by one of: ${definition.dimensions.map((d) => d.key).join(', ')}.`],
    })
  }
  const measureKeys = input.measures ? input.measures.split(',') : definition.measures.map((m) => m.key)
  const measures = definition.measures.filter((measure) => measureKeys.includes(measure.key))
  const filterSeed = JSON.stringify(input.filter ?? {})
  const keys = dimensionKeys(group, input)
  const rows: Row[] = keys.map(([key, label]) => ({
    key,
    label,
    values: Object.fromEntries(
      measures.map((measure) => [
        measure.key,
        value(measure.unit, `${definition.key}|${group}|${key}|${measure.key}|${filterSeed}`),
      ]),
    ),
  }))
  const totals = Object.fromEntries(
    measures.map((measure) => {
      const values = rows.map((row) => row.values[measure.key] ?? 0)
      const sum = values.reduce((total, next) => total + next, 0)
      return [
        measure.key,
        measure.unit === 'count' ? sum : Math.round((sum / Math.max(values.length, 1)) * 10) / 10,
      ]
    }),
  )
  const compare = input.compare === true || input.compare === 'true' || input.compare === '1'
  const now = definition.key === 'rpt-t05' || definition.key === 'rpt-s04'
  const previous =
    compare && !now
      ? Object.fromEntries(
          Object.entries(totals).map(([key, total]) => [key, Math.round((total ?? 0) * 0.8 * 10) / 10]),
        )
      : null
  const { from, days } = periodDays(input)
  return {
    report: definition.key,
    parameters: {
      period: input.from ? null : (input.period ?? 'last_30d'),
      from: `${from}T00:00:00Z`,
      to: `${addDays(from, days)}T00:00:00Z`,
      group,
      measures: measures.map((measure) => measure.key),
      filters: input.filter ?? {},
      compare,
      timezone: 'Asia/Kathmandu',
    },
    rows,
    totals,
    previous,
    truncated: false,
  }
}

function definition(key: unknown): ReportDefinition | undefined {
  return REPORT_DEFINITIONS.find((report) => report.key === String(key))
}

/** The dashboard as `GET /v1/dashboard` composes it: the same runs as the reports it names. */
export function dashboardFixture(period: string): ApiResponseBody<'/dashboard', 'get'>['data'] {
  const tiles: [string, string, string, string][] = [
    ['created', 'rpt-t01', 'created', 'Tickets created'],
    ['resolved', 'rpt-t01', 'resolved', 'Tickets resolved'],
    ['open_now', 'rpt-t05', 'open', 'Open now'],
    ['first_response_median', 'rpt-t06', 'first_response_median', 'Median first response'],
    ['resolution_median', 'rpt-t06', 'resolution_median', 'Median resolution'],
    ['sla_compliance', 'rpt-s01', 'compliance', 'SLA compliance'],
    ['sla_breaches', 'rpt-s01', 'breached', 'SLA breaches'],
    ['reopen_rate', 'rpt-t07', 'reopen_rate', 'Reopen rate'],
  ]
  const series: [string, string, string, string[], string, string][] = [
    ['volume', 'rpt-t01', 'day', ['created', 'resolved'], 'Created and resolved per day', 'line'],
    ['backlog', 'rpt-t02', 'day', ['end_backlog'], 'Backlog at the end of each day', 'stacked_area'],
    [
      'response_by_priority',
      'rpt-t06',
      'priority',
      ['first_response_median', 'resolution_median'],
      'Response and resolution by priority',
      'bar',
    ],
    ['sla_compliance', 'rpt-s01', 'week', ['compliance'], 'SLA compliance per week', 'line'],
    ['agent_workload', 'rpt-a01', 'agent', ['backlog'], 'Open assigned tickets per agent', 'bar'],
    ['time_in_status', 'rpt-t03', 'status', ['median_wall'], 'Median time in status', 'bar'],
  ]
  const run = (key: string, input: RunInput) =>
    runReport(definition(key) as ReportDefinition, input) as ReportRun
  const { from, days } = periodDays({ period })
  return {
    period,
    from: `${from}T00:00:00Z`,
    to: `${addDays(from, days)}T00:00:00Z`,
    timezone: 'Asia/Kathmandu',
    kpis: tiles.map(([key, report, measure, label]) => {
      const result = run(report, { period, compare: true })
      const unit =
        definition(report)?.measures.find((candidate) => candidate.key === measure)?.unit ?? 'count'
      return {
        key,
        label,
        unit,
        value: result.totals[measure] ?? null,
        previous: result.previous?.[measure] ?? null,
        report,
        measure,
      }
    }),
    series: series.map(([key, report, group, measures, title, chart]) => {
      const reportDefinition = definition(report) as ReportDefinition
      return {
        key,
        title,
        chart,
        report,
        report_title: reportDefinition.title,
        parameters: { period, group, measures },
        measures: reportDefinition.measures.filter((measure) => measures.includes(measure.key)),
        rows: run(report, { period, group, measures: measures.join(',') }).rows,
        truncated: false,
      }
    }),
  }
}

export const reportHandlers = [
  http.get(apiUrl('/reports'), () =>
    HttpResponse.json<ApiResponseBody<'/reports', 'get'>>({ data: REPORT_DEFINITIONS }),
  ),
  http.get(apiUrl('/reports/{report}'), ({ params }) => {
    const found = definition(params.report)
    return found ? HttpResponse.json({ data: found }) : problem(404, 'not_found', { title: 'Not found' })
  }),
  http.post(apiUrl('/reports/{report}/run'), async ({ params, request }) => {
    const found = definition(params.report)
    if (!found) return problem(404, 'not_found', { title: 'Not found' })
    const body = ((await request.json().catch(() => ({}))) ?? {}) as RunInput
    reportRequests.run.push({ report: found.key, body })
    const result = runReport(found, body)
    return result instanceof Response ? result : HttpResponse.json({ data: result })
  }),
  http.get(apiUrl('/reports/{report}/records'), ({ params, request }) => {
    const found = definition(params.report)
    if (!found?.drill_down_to) return problem(404, 'not_found', { title: 'Not found' })
    const query = new URL(request.url).searchParams
    reportRequests.records.push(query)
    const key = query.get('key') ?? '*'
    const page = Math.max(1, Number(query.get('page') ?? '1'))
    const records = db.tickets
      .filter((ticket, index) => key === '*' || noise(`${key}|${index}`) < 0.5 || ticket.number % 7 === 0)
      .map((ticket) => ({
        id: ticket.id,
        entity: 'tickets',
        label: `#${ticket.number} ${ticket.title}`,
        subtitle: null,
        status: ticket.status,
      }))
    return HttpResponse.json({
      data: records.slice((page - 1) * 50, page * 50),
      meta: { entity: 'tickets', current_page: page, per_page: 50 as const, total: records.length },
    })
  }),
  http.get(apiUrl('/dashboard'), ({ request }) => {
    const period = new URL(request.url).searchParams.get('period') ?? 'last_30d'
    if (!(period in PERIODS)) return validationFailed({ period: ['The selected period is invalid.'] })
    return HttpResponse.json({ data: dashboardFixture(period) })
  }),
]

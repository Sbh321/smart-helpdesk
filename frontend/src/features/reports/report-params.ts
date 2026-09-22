import { z } from 'zod'

/**
 * The report page's URL state (docs/04-domain/reporting.md §Report page behaviour). Every parameter of
 * a run lives in the address bar, so a report can be shared inside the workspace:
 * `/acme/reports/rpt-t06?period=last_7d&group=team&compare=previous&team=<id>,none`.
 *
 * Reserved keys: `period`, `from` + `to` (a custom range, `YYYY-MM-DD`, inclusive; it wins over
 * `period`), `compare=previous`, `group`, `chart` (the measure the chart shows), `drill` (the row key whose
 * records are open; `*` for the whole period), `drill_measure` (the count measure clicked; absent: the whole row)
 * and `drill_page`. Any other key is a report filter, a comma
 * list as on the API (`filter[team]=a,b`).
 */
export const PERIODS = [
  'today',
  'yesterday',
  'last_7d',
  'last_30d',
  'last_90d',
  'this_month',
  'last_month',
  'this_year',
] as const
export type Period = (typeof PERIODS)[number]
export const DEFAULT_PERIOD: Period = 'last_30d'

/** The drill-down key of "the whole period" (the API's records endpoint without `key`). */
export const ALL_RECORDS = '*'

const RESERVED = [
  'period',
  'from',
  'to',
  'compare',
  'group',
  'chart',
  'drill',
  'drill_measure',
  'drill_page',
] as const

export interface ReportParams {
  period: Period
  /** A custom range; when set, `period` is not sent. */
  range: { from: string; to: string } | undefined
  compare: boolean
  /** `undefined`: the report's default dimension. */
  group: string | undefined
  /** `undefined`: the default chart measures. */
  chart: string | undefined
  filters: Record<string, string[]>
  drill: string | undefined
  /** The count measure whose records are open; `undefined`: every record of the row. */
  drillMeasure?: string | undefined
  drillPage: number
}

/** What a report run takes (`POST /v1/reports/{key}/run`). */
export interface RunBody {
  period?: string
  from?: string
  to?: string
  group?: string
  measures?: string
  filter?: Record<string, string>
  compare?: boolean
}

function toText(value: unknown): string | undefined {
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  return undefined
}

function splitList(value: unknown): string[] {
  if (Array.isArray(value)) return value.flatMap(splitList)
  const text = toText(value)
  if (text === undefined) return []
  return [
    ...new Set(
      text
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== ''),
    ),
  ]
}

const isoDate = z.iso.date()

/** For the route's `validateSearch`: never throws, keeps unknown keys (the filters) for the screen. */
export const reportSearchSchema = z.looseObject({
  period: z.enum(PERIODS).optional().catch(undefined),
  from: z.preprocess(toText, isoDate).optional().catch(undefined),
  to: z.preprocess(toText, isoDate).optional().catch(undefined),
  compare: z.preprocess(toText, z.literal('previous')).optional().catch(undefined),
  group: z.preprocess(toText, z.string().min(1).max(50)).optional().catch(undefined),
  chart: z.preprocess(toText, z.string().min(1).max(50)).optional().catch(undefined),
  drill: z.preprocess(toText, z.string().min(1).max(200)).optional().catch(undefined),
  drill_measure: z.preprocess(toText, z.string().min(1).max(50)).optional().catch(undefined),
  drill_page: z.coerce.number().int().min(1).max(10_000).optional().catch(undefined),
})

export type ReportSearch = z.infer<typeof reportSearchSchema>

/** Raw search → params. Filters are kept only for keys the report declares. */
export function parseReportSearch(raw: Record<string, unknown>, filterKeys: readonly string[]): ReportParams {
  const search = reportSearchSchema.parse(raw)
  const range =
    search.from !== undefined && search.to !== undefined && search.from <= search.to
      ? { from: search.from, to: search.to }
      : undefined
  const filters: Record<string, string[]> = {}
  for (const key of filterKeys) {
    if ((RESERVED as readonly string[]).includes(key)) continue
    const values = splitList(raw[key]).slice(0, 50)
    if (values.length > 0) filters[key] = values
  }
  return {
    period: search.period ?? DEFAULT_PERIOD,
    range,
    compare: search.compare === 'previous',
    group: search.group,
    chart: search.chart,
    filters,
    drill: search.drill,
    drillMeasure: search.drill !== undefined ? search.drill_measure : undefined,
    drillPage: search.drill_page ?? 1,
  }
}

/**
 * Params → search. Defaults are left out; `current` keys that are not report parameters (and not
 * filters of this report) pass through untouched.
 */
export function toReportSearch(
  params: ReportParams,
  filterKeys: readonly string[],
  current: Record<string, unknown> = {},
): Record<string, unknown> {
  const next: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(current)) {
    if (!(RESERVED as readonly string[]).includes(key) && !filterKeys.includes(key)) next[key] = value
  }
  if (params.range) {
    next.from = params.range.from
    next.to = params.range.to
  } else if (params.period !== DEFAULT_PERIOD) {
    next.period = params.period
  }
  if (params.compare) next.compare = 'previous'
  if (params.group !== undefined) next.group = params.group
  if (params.chart !== undefined) next.chart = params.chart
  for (const key of filterKeys) {
    const values = params.filters[key]
    if (values && values.length > 0) next[key] = values.join(',')
  }
  if (params.drill !== undefined) {
    next.drill = params.drill
    if (params.drillMeasure !== undefined) next.drill_measure = params.drillMeasure
    if (params.drillPage > 1) next.drill_page = params.drillPage
  }
  return next
}

/** The period part of a run or records request. */
function periodInput(params: ReportParams): { period?: string; from?: string; to?: string } {
  return params.range ? { from: params.range.from, to: params.range.to } : { period: params.period }
}

function filterInput(params: ReportParams): Record<string, string> | undefined {
  const entries = Object.entries(params.filters).filter(([, values]) => values.length > 0)
  return entries.length > 0
    ? Object.fromEntries(entries.map(([key, values]) => [key, values.join(',')]))
    : undefined
}

/** Params → the run request body. */
export function toRunBody(params: ReportParams): RunBody {
  const filter = filterInput(params)
  return {
    ...periodInput(params),
    ...(params.group !== undefined ? { group: params.group } : {}),
    ...(filter ? { filter } : {}),
    ...(params.compare ? { compare: true } : {}),
  }
}

/** Params → the records query (`GET /v1/reports/{key}/records`) for one row, or for the totals. */
export function toRecordsQuery(
  params: ReportParams,
  rowKey: string,
  page: number,
): {
  period?: string
  from?: string
  to?: string
  group?: string
  filter?: Record<string, string>
  key?: string
  measure?: string
  page: number
} {
  const filter = filterInput(params)
  return {
    ...periodInput(params),
    ...(params.group !== undefined ? { group: params.group } : {}),
    ...(filter ? { filter } : {}),
    ...(rowKey !== ALL_RECORDS ? { key: rowKey } : {}),
    ...(params.drillMeasure !== undefined ? { measure: params.drillMeasure } : {}),
    page,
  }
}

/** The dashboard's URL state: `?period=` (the default, last 30 days, is left out). */
export const dashboardSearchSchema = z.looseObject({
  period: z.enum(PERIODS).optional().catch(undefined),
})

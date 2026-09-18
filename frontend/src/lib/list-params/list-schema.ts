import { z } from 'zod'

/**
 * List query parameters shared by the router and the API (docs/07-api/pagination-filtering.md,
 * docs/03-architecture/frontend.md §State by kind). A list route's search params use the API's names:
 * `page`, `per_page`, `sort` (`field` or `-field`), `search`, and one flat key per filter
 * (`?organization_id=a,b` in the address bar is `filter[organization_id]=a,b` on the API call).
 * Multi-value filters are comma lists, date ranges are `YYYY-MM-DD,YYYY-MM-DD` (inclusive).
 */

export const PAGE_SIZES = [25, 50, 100] as const
export type PageSize = (typeof PAGE_SIZES)[number]
export const DEFAULT_PAGE_SIZE: PageSize = 25
export const SEARCH_MAX_LENGTH = 200

const RESERVED_KEYS = ['page', 'per_page', 'sort', 'search'] as const

export type SortValue<TField extends string> = TField | `-${TField}`

/**
 * A sort as the API takes it: one field, or two comma-separated fields (`-priority_score,-created_at`).
 * Two-field sorts are accepted from the URL only when the schema allows them (`multiSort`), but a
 * two-field default is always allowed. `id ASC` is the server's own last tie-breaker.
 */
export type SortSpec<TField extends string> = SortValue<TField> | `${SortValue<TField>},${SortValue<TField>}`

const MAX_SORT_FIELDS = 2

export type MultiFilterDef = { kind: 'multi'; item: z.ZodType<string> }
export type DateRangeFilterDef = { kind: 'date-range' }
export type ChoiceFilterDef = { kind: 'choice'; values: readonly string[] }
export type FilterDef = MultiFilterDef | DateRangeFilterDef | ChoiceFilterDef
export type FilterDefs = Record<string, FilterDef>

/** Inclusive calendar dates in the tenant's time zone, as the API expects them. */
export type DateRangeValue = { from: string; to: string }

/** A comma list of values, OR within the field (`filter[tag]=vip,beta`). Invalid items are dropped. */
export function multiFilter(item: z.ZodType<string> = z.string().trim().min(1).max(100)): MultiFilterDef {
  return { kind: 'multi', item }
}

/** Exactly one of a fixed set of values, for example `filter[archived]=true|all`. */
export function choiceFilter<const TValue extends string>(
  values: readonly TValue[],
): ChoiceFilterDef & {
  values: readonly TValue[]
} {
  return { kind: 'choice', values }
}

/** `YYYY-MM-DD,YYYY-MM-DD`, for example `filter[created_between]`. */
export function dateRangeFilter(): DateRangeFilterDef {
  return { kind: 'date-range' }
}

type FilterValue<TDef> = TDef extends MultiFilterDef
  ? string[]
  : TDef extends DateRangeFilterDef
    ? DateRangeValue
    : TDef extends { kind: 'choice'; values: readonly (infer TValue)[] }
      ? TValue
      : never

export type FilterValues<TFilters extends FilterDefs> = {
  [K in keyof TFilters]?: FilterValue<TFilters[K]>
}

/** The resolved, validated state of a list: every field has a value, defaults filled in. */
export interface ListParams<TSort extends string, TFilters extends FilterDefs> {
  page: number
  per_page: PageSize
  sort: SortSpec<TSort>
  search: string | undefined
  filters: FilterValues<TFilters>
}

/** A change to a list. Any key other than `page` sends the list back to page 1. */
export type ListPatch<TSort extends string, TFilters extends FilterDefs> = {
  page?: number
  per_page?: PageSize
  /** `undefined` goes back to the default sort. */
  sort?: SortSpec<TSort> | undefined
  search?: string | undefined
  /** A key set to `undefined` (or an empty list) removes that filter. */
  filters?: FilterValues<TFilters>
}

/**
 * The query object for the API call. Filter keys are flat (`'filter[tag]': 'vip,beta'`), which is how
 * the generated OpenAPI operation types name them, so the object can be passed to openapi-fetch as is.
 */
export type ApiListQuery = {
  page: number
  per_page: number
  sort: string
  search?: string
} & { [filter: `filter[${string}]`]: string }

/** What the route's `validateSearch` produces and accepts: optional values plus unrelated params. */
export type ListSearch = {
  page?: number
  per_page?: number
  sort?: string
  search?: string
} & Record<string, unknown>

export interface ListSchema<TSort extends string, TFilters extends FilterDefs> {
  /** For the route's `validateSearch`. Never throws: invalid values become `undefined` (the default). */
  searchSchema: z.ZodType<ListSearch, ListSearch>
  sortFields: readonly TSort[]
  defaultSort: SortSpec<TSort>
  filters: TFilters
  isSortAllowed(value: unknown): value is SortSpec<TSort>
  /** Raw router search → resolved params. */
  parse(raw: unknown): ListParams<TSort, TFilters>
  /** Resolved params → router search: defaults are left out, unrelated keys of `current` are kept. */
  toSearch(params: ListParams<TSort, TFilters>, current?: Record<string, unknown>): Record<string, unknown>
  toApiQuery(params: ListParams<TSort, TFilters>): ApiListQuery
}

export interface ListSchemaOptions<TSort extends string, TFilters extends FilterDefs> {
  /** The endpoint's sort allow-list (docs/07-api/pagination-filtering.md §Sorting). */
  sortFields: readonly TSort[]
  defaultSort: NoInfer<SortSpec<TSort>>
  /** Accept two-field sorts from the URL too (default false: one field, or exactly the default). */
  multiSort?: boolean
  filters: TFilters
}

/** The primary (first) field of a sort and its direction. */
export function parseSort(value: string): { field: string; desc: boolean } {
  const first = value.split(',')[0] ?? ''
  return first.startsWith('-') ? { field: first.slice(1), desc: true } : { field: first, desc: false }
}

export function formatSort<TField extends string>(field: TField, desc: boolean): SortValue<TField> {
  return desc ? `-${field}` : field
}

function toText(value: unknown): unknown {
  return typeof value === 'number' || typeof value === 'boolean' ? String(value) : value
}

/** Accepts `a,b`, a JSON array (`["a","b"]` after the router parsed it) or a single scalar. */
function splitList(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.flatMap(splitList)
  }
  const text = toText(value)
  if (typeof text !== 'string') {
    return []
  }
  return text
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item !== '')
}

const isoDate = z.iso.date()

function parseDateRange(value: unknown): DateRangeValue | undefined {
  const [from, to, ...rest] = splitList(value)
  if (from === undefined || to === undefined || rest.length > 0) {
    return undefined
  }
  if (!isoDate.safeParse(from).success || !isoDate.safeParse(to).success || from > to) {
    return undefined
  }
  return { from, to }
}

function isPageSize(value: number): value is PageSize {
  return (PAGE_SIZES as readonly number[]).includes(value)
}

function filterSchema(def: FilterDef): z.ZodType<unknown> {
  if (def.kind === 'choice') {
    return z
      .unknown()
      .transform((value) => {
        const text = toText(value)
        return typeof text === 'string' && def.values.includes(text) ? text : undefined
      })
      .optional()
  }
  if (def.kind === 'date-range') {
    return z
      .unknown()
      .transform((value) => {
        const range = parseDateRange(value)
        return range ? `${range.from},${range.to}` : undefined
      })
      .optional()
  }
  return z
    .unknown()
    .transform((value) => {
      const items = [...new Set(splitList(value).filter((item) => def.item.safeParse(item).success))]
      return items.length > 0 ? items.join(',') : undefined
    })
    .optional()
}

/**
 * Describes one list endpoint: its sort allow-list, default sort and filters. The result is used twice:
 * as the route's `validateSearch`, and by `useListParams` to read and write the URL.
 */
export function defineListSchema<const TSort extends string, const TFilters extends FilterDefs>(
  options: ListSchemaOptions<TSort, TFilters>,
): ListSchema<TSort, TFilters> {
  const { sortFields, defaultSort, filters, multiSort = false } = options
  const filterKeys = Object.keys(filters)

  for (const key of filterKeys) {
    if ((RESERVED_KEYS as readonly string[]).includes(key)) {
      throw new Error(`"${key}" is a reserved list parameter and cannot be a filter`)
    }
  }

  const isSortSpec = (value: unknown, allowMulti: boolean): value is SortSpec<TSort> => {
    if (typeof value !== 'string' || value === '') {
      return false
    }
    const parts = value.split(',')
    if (parts.length > (allowMulti ? MAX_SORT_FIELDS : 1)) {
      return false
    }
    const fields = parts.map((part) => (part.startsWith('-') ? part.slice(1) : part))
    return (
      new Set(fields).size === fields.length &&
      fields.every((field) => (sortFields as readonly string[]).includes(field))
    )
  }
  const isSortAllowed = (value: unknown): value is SortSpec<TSort> =>
    value === defaultSort ? isSortSpec(value, true) : isSortSpec(value, multiSort)

  if (!isSortAllowed(defaultSort)) {
    throw new Error(`The default sort "${defaultSort}" is not in the sort allow-list`)
  }

  const shape: Record<string, z.ZodType<unknown>> = {
    page: z.coerce.number().int().min(1).max(1_000_000).optional().catch(undefined),
    per_page: z.coerce
      .number()
      .refine((value) => isPageSize(value))
      .optional()
      .catch(undefined),
    sort: z
      .unknown()
      .refine((value) => isSortAllowed(value))
      .transform((value) => value as string)
      .optional()
      .catch(undefined),
    search: z.preprocess(toText, z.string().trim().min(1).max(SEARCH_MAX_LENGTH)).optional().catch(undefined),
  }
  for (const key of filterKeys) {
    const def = filters[key]
    if (def) {
      shape[key] = filterSchema(def).catch(undefined)
    }
  }

  // Loose: parameters that belong to someone else (for example a dialog's `?contact=`) pass through.
  const searchSchema = z
    .looseObject(shape)
    .transform((value) =>
      Object.fromEntries(Object.entries(value).filter(([, entry]) => entry !== undefined)),
    ) as unknown as z.ZodType<ListSearch, ListSearch>

  const parse = (raw: unknown): ListParams<TSort, TFilters> => {
    const input = raw !== null && typeof raw === 'object' ? raw : {}
    const flat = searchSchema.parse(input) as Record<string, unknown>
    const resolved: FilterValues<FilterDefs> = {}
    for (const key of filterKeys) {
      const value = flat[key]
      const def = filters[key]
      if (typeof value !== 'string' || !def) {
        continue
      }
      resolved[key] =
        def.kind === 'date-range' ? parseDateRange(value) : def.kind === 'choice' ? value : splitList(value)
    }
    const perPage = typeof flat.per_page === 'number' && isPageSize(flat.per_page) ? flat.per_page : undefined
    return {
      page: typeof flat.page === 'number' ? flat.page : 1,
      per_page: perPage ?? DEFAULT_PAGE_SIZE,
      sort: isSortAllowed(flat.sort) ? flat.sort : defaultSort,
      search: typeof flat.search === 'string' ? flat.search : undefined,
      filters: resolved as FilterValues<TFilters>,
    }
  }

  const serialiseFilter = (key: string, value: unknown): string | undefined => {
    const def = filters[key]
    if (!def || value === undefined) {
      return undefined
    }
    if (def.kind === 'date-range') {
      const range = value as DateRangeValue
      return `${range.from},${range.to}`
    }
    if (def.kind === 'choice') {
      return typeof value === 'string' && def.values.includes(value) ? value : undefined
    }
    const items = value as string[]
    return items.length > 0 ? items.join(',') : undefined
  }

  const toSearch = (
    params: ListParams<TSort, TFilters>,
    current: Record<string, unknown> = {},
  ): Record<string, unknown> => {
    const ownKeys = new Set<string>([...RESERVED_KEYS, ...filterKeys])
    const next: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(current)) {
      if (!ownKeys.has(key) && value !== undefined) {
        next[key] = value
      }
    }
    if (params.page > 1) next.page = params.page
    if (params.per_page !== DEFAULT_PAGE_SIZE) next.per_page = params.per_page
    if (params.sort !== defaultSort) next.sort = params.sort
    if (params.search) next.search = params.search
    for (const key of filterKeys) {
      const value = serialiseFilter(key, (params.filters as Record<string, unknown>)[key])
      if (value !== undefined) next[key] = value
    }
    return next
  }

  const toApiQuery = (params: ListParams<TSort, TFilters>): ApiListQuery => {
    const query: ApiListQuery = { page: params.page, per_page: params.per_page, sort: params.sort }
    if (params.search) query.search = params.search
    for (const key of filterKeys) {
      const value = serialiseFilter(key, (params.filters as Record<string, unknown>)[key])
      if (value !== undefined) query[`filter[${key}]`] = value
    }
    return query
  }

  return { searchSchema, sortFields, defaultSort, filters, isSortAllowed, parse, toSearch, toApiQuery }
}

/**
 * Applies a change to resolved params. Changing anything but the page (sort, page size, search or a
 * filter) goes back to page 1, because page 7 of a different result set means nothing.
 */
export function applyListPatch<TSort extends string, TFilters extends FilterDefs>(
  params: ListParams<TSort, TFilters>,
  patch: ListPatch<TSort, TFilters>,
  defaultSort: SortSpec<TSort>,
): ListParams<TSort, TFilters> {
  const resetsPage = Object.keys(patch).some((key) => key !== 'page')
  const filters: Record<string, unknown> = { ...params.filters }
  for (const [key, value] of Object.entries(patch.filters ?? {})) {
    if (value === undefined || (Array.isArray(value) && value.length === 0)) {
      delete filters[key]
    } else {
      filters[key] = value
    }
  }
  const search =
    'search' in patch ? patch.search?.trim().slice(0, SEARCH_MAX_LENGTH) || undefined : params.search
  return {
    page: patch.page !== undefined ? Math.max(1, Math.trunc(patch.page)) : resetsPage ? 1 : params.page,
    per_page: patch.per_page ?? params.per_page,
    sort: 'sort' in patch ? (patch.sort ?? defaultSort) : params.sort,
    search,
    filters: filters as FilterValues<TFilters>,
  }
}

/** Number of active filters, counting the free-text search as one. */
export function countActiveFilters<TSort extends string, TFilters extends FilterDefs>(
  params: ListParams<TSort, TFilters>,
): number {
  return Object.values(params.filters).filter((value) => value !== undefined).length + (params.search ? 1 : 0)
}

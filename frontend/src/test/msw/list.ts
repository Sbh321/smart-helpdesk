import { HttpResponse } from 'msw'
import { problem } from './handlers'

/**
 * The list contract of docs/07-api/pagination-filtering.md as the backend implements it: page-based
 * pagination with `meta`/`links`, a sort allow-list with `-` for descending, per-endpoint filter
 * allow-lists; unknown sorts and filters are `422 validation_failed`.
 */
export function compare(a: unknown, b: unknown): number {
  if (a === b) return 0
  if (a === null || a === undefined) return 1
  if (b === null || b === undefined) return -1
  if (typeof a === 'number' && typeof b === 'number') return a - b
  return String(a).localeCompare(String(b))
}

export function paginate<T>(url: URL, rows: T[]) {
  const page = Number(url.searchParams.get('page') ?? '1')
  const perPage = Number(url.searchParams.get('per_page') ?? '25')
  const total = rows.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const start = (page - 1) * perPage
  const data = rows.slice(start, start + perPage)
  const link = (target: number) => {
    const next = new URL(url)
    next.searchParams.set('page', String(target))
    return next.toString()
  }
  return {
    data,
    links: {
      first: link(1),
      last: link(lastPage),
      prev: page > 1 ? link(page - 1) : null,
      next: page < lastPage ? link(page + 1) : null,
    },
    meta: {
      current_page: page,
      from: data.length > 0 ? start + 1 : null,
      last_page: lastPage,
      links: [],
      path: `${url.origin}${url.pathname}`,
      per_page: perPage,
      to: data.length > 0 ? start + data.length : null,
      total,
    },
  }
}

/** A 422 for an invalid page, page size, sort or filter; `undefined` when the query is valid. */
export function validateListQuery(
  url: URL,
  options: { sortable: readonly string[]; filters: readonly string[]; defaultSort: string },
): Response | undefined {
  const page = url.searchParams.get('page')
  const perPage = url.searchParams.get('per_page')
  if (page !== null && !(Number.isInteger(Number(page)) && Number(page) >= 1)) {
    return problem(422, 'validation_failed', { errors: { page: ['The page field must be at least 1.'] } })
  }
  if (
    perPage !== null &&
    !(Number.isInteger(Number(perPage)) && Number(perPage) >= 1 && Number(perPage) <= 100)
  ) {
    return problem(422, 'validation_failed', {
      errors: { per_page: ['The per page field must be between 1 and 100.'] },
    })
  }
  for (const sort of (url.searchParams.get('sort') ?? options.defaultSort).split(',')) {
    const field = sort.startsWith('-') ? sort.slice(1) : sort
    if (!options.sortable.includes(field)) {
      return problem(422, 'validation_failed', {
        errors: { sort: [`The sort field "${field}" is not allowed.`] },
      })
    }
  }
  for (const key of url.searchParams.keys()) {
    const match = /^filter\[(.+)\]$/.exec(key)
    if (match?.[1] && !options.filters.includes(match[1])) {
      return problem(422, 'validation_failed', {
        errors: { [`filter.${match[1]}`]: [`The filter "${match[1]}" is not allowed.`] },
      })
    }
  }
  return undefined
}

export function sortRows<T extends { id: string }>(rows: T[], sort: string): T[] {
  return rows.sort((a, b) => {
    for (const part of sort.split(',')) {
      const desc = part.startsWith('-')
      const field = (desc ? part.slice(1) : part) as keyof T
      const result = compare(a[field], b[field])
      if (result !== 0) return desc ? -result : result
    }
    return a.id.localeCompare(b.id)
  })
}

/** A 422 in the backend's shape for the given field messages. */
export function validationFailed(errors: Record<string, string[]>): Response {
  return problem(422, 'validation_failed', { title: 'The given data was invalid.', errors })
}

export const json = HttpResponse.json

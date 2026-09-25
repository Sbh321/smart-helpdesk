import { useState } from 'react'
import {
  applyListPatch,
  countActiveFilters,
  type FilterDefs,
  type FilterValues,
  type ListPatch,
  type ListSchema,
} from './list-schema'
import type { UseListParamsResult } from './use-list-params'

/**
 * `useListParams` without the router: the same state and setters, held in component state. For a list
 * inside a dialog (the media picker), whose filters must not rewrite the address of the page behind it.
 * `initial` is parsed like search params, so it takes the same keys (`{ type: 'image' }`).
 */
export function useLocalListParams<TSort extends string, TFilters extends FilterDefs>(
  schema: ListSchema<TSort, TFilters>,
  initial: Record<string, unknown> = {},
): UseListParamsResult<TSort, TFilters> {
  const [params, setParams] = useState(() => schema.parse(initial))

  const update = (patch: ListPatch<TSort, TFilters>) =>
    setParams((current) => applyListPatch(current, patch, schema.defaultSort))

  return {
    params,
    apiQuery: schema.toApiQuery(params),
    activeFilterCount: countActiveFilters(params),
    setPage: (page) => update({ page }),
    setPerPage: (perPage) => update({ per_page: perPage }),
    setSort: (sort) => update({ sort }),
    setSearch: (search) => update({ search }),
    setFilter: (key, value) => update({ filters: { [key]: value } as FilterValues<TFilters> }),
    clearFilters: () => {
      const cleared = Object.fromEntries(Object.keys(schema.filters).map((key) => [key, undefined]))
      update({ search: undefined, filters: cleared as FilterValues<TFilters> })
    },
    update,
  }
}

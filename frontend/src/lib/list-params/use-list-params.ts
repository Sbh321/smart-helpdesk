import { useLocation, useNavigate, useRouter } from '@tanstack/react-router'
import {
  type ApiListQuery,
  applyListPatch,
  countActiveFilters,
  type FilterDefs,
  type FilterValues,
  type ListParams,
  type ListPatch,
  type ListSchema,
  type PageSize,
  type SortSpec,
} from './list-schema'

export interface UseListParamsResult<TSort extends string, TFilters extends FilterDefs> {
  /** The validated state of the list, read from the URL. */
  params: ListParams<TSort, TFilters>
  /** The same state as the API query object (`filter[key]` keys, comma lists). */
  apiQuery: ApiListQuery
  /** Filters plus search currently narrowing the list. */
  activeFilterCount: number
  setPage(page: number): void
  setPerPage(perPage: PageSize): void
  /** `undefined` goes back to the default sort. */
  setSort(sort: SortSpec<TSort> | undefined): void
  setSearch(search: string | undefined): void
  setFilter<K extends keyof TFilters & string>(key: K, value: FilterValues<TFilters>[K] | undefined): void
  /** Removes every filter and the search; sort and page size stay. */
  clearFilters(): void
  update(patch: ListPatch<TSort, TFilters>, options?: { replace?: boolean }): void
}

/**
 * Binds a list to the router's search params (docs/06-design-system/components.md §DataTable). The URL
 * is the only state: every change is a navigation, so reload, share and back/forward all restore the
 * list. Writes read the location at call time rather than the rendered one, so two quick changes (a
 * debounced search after a filter click) never overwrite each other.
 */
export function useListParams<TSort extends string, TFilters extends FilterDefs>(
  schema: ListSchema<TSort, TFilters>,
): UseListParamsResult<TSort, TFilters> {
  const router = useRouter()
  const navigate = useNavigate()
  const rawSearch = useLocation({ select: (location) => location.search })
  const params = schema.parse(rawSearch)

  const update = (patch: ListPatch<TSort, TFilters>, options: { replace?: boolean } = {}) => {
    const current = router.state.location.search as Record<string, unknown>
    const next = applyListPatch(schema.parse(current), patch, schema.defaultSort)
    void navigate({
      to: '.',
      // The target is "this route"; its search type is not known here, the schema validated it.
      search: schema.toSearch(next, current) as never,
      replace: options.replace ?? false,
    })
  }

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

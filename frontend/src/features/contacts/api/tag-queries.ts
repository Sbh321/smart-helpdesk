import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'

export const tagQueries = {
  /** Every tag, as filter options (`value` is the slug the list filters by). */
  options: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.tags.options(tenantId),
      queryFn: async () => {
        const tags = await unwrap(api().GET('/tags'))
        return tags.map((tag) => ({ value: tag.slug, label: tag.name }))
      },
      staleTime: 60_000,
    }),
  /** Tag names matching what was typed, for the tag input. */
  search: (tenantId: string, q: string) =>
    queryOptions({
      queryKey: queryKeys.tags.search(tenantId, q),
      queryFn: async () => {
        const search = q.trim()
        const tags = await unwrap(api().GET('/tags', { params: { query: search ? { search } : {} } }))
        return tags.map((tag) => tag.name)
      },
      placeholderData: keepPreviousData,
      staleTime: 30_000,
    }),
}

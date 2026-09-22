import { infiniteQueryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, defineListSchema, multiFilter } from '@/lib/list-params'

export type InboundEmail = components['schemas']['InboundEmailResource']
export type InboundState = InboundEmail['state']
type InboundQuery = NonNullable<operations['inbound-emails.index']['parameters']['query']>

/** What became of a message (docs/04-domain/email.md §Inbound pipeline). */
export const INBOUND_STATES = ['comment', 'ticket', 'ignored', 'unrouted', 'rejected'] as const

/**
 * The inbound log's URL state on Settings → Email: the state filter only; the feed has one fixed
 * order (newest first), so `sort`, `page` and `search` never reach the API.
 */
export const inboundListSchema = defineListSchema({
  sortFields: ['created_at'] as const,
  defaultSort: '-created_at',
  filters: {
    state: multiFilter(z.enum(INBOUND_STATES)),
  },
})

export function inboundApiQuery(query: ApiListQuery): InboundQuery {
  const { page: _page, sort: _sort, search: _search, ...rest } = query
  return rest as InboundQuery
}

export const inboundEmailQueries = {
  /** `GET /v1/inbound-emails`: newest first; each page's `meta.next_cursor` fetches the next older page. */
  list: (tenantId: string, query: InboundQuery) =>
    infiniteQueryOptions({
      queryKey: queryKeys.inboundEmails.list(tenantId, query),
      initialPageParam: undefined as string | undefined,
      queryFn: ({ pageParam }) =>
        unwrapBody(api().GET('/inbound-emails', { params: { query: { ...query, cursor: pageParam } } })),
      getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    }),
}

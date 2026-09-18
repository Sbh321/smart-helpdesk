import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { PRIORITY_LEVELS } from '@/components/shared/priority-badge'
import { TICKET_STATUSES } from '@/components/shared/status-badge'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, dateRangeFilter, defineListSchema, multiFilter } from '@/lib/list-params'

export type Ticket = components['schemas']['TicketResource']
export type TicketInput = components['schemas']['StoreTicketRequest']
export type TicketEvent = components['schemas']['TicketEventResource']
export type Category = components['schemas']['CategoryResource']

type TicketIndex = operations['tickets.index']
type TicketListQuery = NonNullable<TicketIndex['parameters']['query']>
export type TicketListPage = TicketIndex['responses'][200]['content']['application/json']

/** `filter[status]=active` is the API's alias for every status but resolved and closed. */
export const ACTIVE_STATUS = 'active'

/**
 * The ticket list's URL and API parameters (docs/07-api/pagination-filtering.md §Ticket list filters).
 * The default order is two fields, priority first and the newest first among equals.
 */
export const ticketListSchema = defineListSchema({
  sortFields: ['priority_score', 'priority_level', 'created_at', 'updated_at', 'number', 'status'],
  defaultSort: '-priority_score,-created_at',
  filters: {
    status: multiFilter(z.enum([ACTIVE_STATUS, ...TICKET_STATUSES])),
    priority: multiFilter(z.enum(PRIORITY_LEVELS)),
    category_id: multiFilter(z.uuid()),
    created_between: dateRangeFilter(),
  },
})

/** The list embeds the contact and the category, so a row needs no second request. */
export const TICKET_LIST_INCLUDE = 'contact,category'

export function listTickets(query: ApiListQuery): Promise<TicketListPage> {
  const withIncludes: TicketListQuery = { ...(query as TicketListQuery), include: TICKET_LIST_INCLUDE }
  return unwrapBody(api().GET('/tickets', { params: { query: withIncludes } }))
}

const HISTORY_PAGE_SIZE = 50

export const ticketQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.tickets.list(tenantId, query),
      queryFn: () => listTickets(query),
      placeholderData: keepPreviousData,
      refetchInterval: 30_000,
    }),
  detail: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.tickets.detail(tenantId, id),
      queryFn: () => unwrap(api().GET('/tickets/{ticket}', { params: { path: { ticket: id } } })),
    }),
  // MVP-SHORTCUT: only the newest 50 history entries (the first cursor page); V1: "load older" with
  // `cursor=` (now in the generated query type), V1-FE-08 or the M2-06 ticket page.
  history: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.tickets.history(tenantId, id),
      queryFn: () =>
        unwrap(
          api().GET('/tickets/{ticket}/history', {
            params: { path: { ticket: id }, query: { per_page: HISTORY_PAGE_SIZE } },
          }),
        ),
    }),
}

export const categoryQueries = {
  all: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.categories.all(tenantId),
      queryFn: () => unwrap(api().GET('/categories')),
      staleTime: 5 * 60_000,
    }),
}

export function createTicket(input: TicketInput): Promise<Ticket> {
  return unwrap(api().POST('/tickets', { body: input }))
}

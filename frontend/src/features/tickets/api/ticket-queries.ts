import { infiniteQueryOptions, keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { PRIORITY_LEVELS } from '@/components/shared/priority-badge'
import { TICKET_STATUSES } from '@/components/shared/status-badge'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import {
  type ApiListQuery,
  choiceFilter,
  dateRangeFilter,
  defineListSchema,
  multiFilter,
} from '@/lib/list-params'

export type Ticket = components['schemas']['TicketResource']
export type TicketInput = components['schemas']['StoreTicketRequest']
export type TicketEvent = components['schemas']['TicketEventResource']
export type Category = components['schemas']['CategoryResource']

type TicketIndex = operations['tickets.index']
type TicketListQuery = NonNullable<TicketIndex['parameters']['query']>
export type TicketListPage = TicketIndex['responses'][200]['content']['application/json']

/** `filter[status]=active` is the API's alias for every status but resolved and closed. */
export const ACTIVE_STATUS = 'active'
/** `filter[assignee_id]` tokens: no Agent, and the signed-in user's own Agent profile. */
export const UNASSIGNED = 'unassigned'
export const ASSIGNED_TO_ME = 'me'
/** `filter[team_id]=none`: tickets without a Team. */
export const NO_TEAM = 'none'
/** `filter[sla_state]`: the state of the latest resolution timer. */
export const SLA_STATES = ['running', 'warning', 'breached', 'paused', 'met'] as const
export type SlaState = (typeof SLA_STATES)[number]

const uuidOr = (token: string) => z.union([z.uuid(), z.literal(token)])

/**
 * The ticket list's URL and API parameters (docs/07-api/pagination-filtering.md §Ticket list filters).
 * The default order is two fields, priority first and the newest first among equals.
 */
export const ticketListSchema = defineListSchema({
  sortFields: [
    'priority_score',
    'priority_level',
    'created_at',
    'updated_at',
    'number',
    'status',
    'sla_due_at',
  ],
  defaultSort: '-priority_score,-created_at',
  filters: {
    status: multiFilter(z.enum([ACTIVE_STATUS, ...TICKET_STATUSES])),
    priority: multiFilter(z.enum(PRIORITY_LEVELS)),
    assignee_id: multiFilter(z.union([z.uuid(), z.literal(UNASSIGNED), z.literal(ASSIGNED_TO_ME)])),
    team_id: multiFilter(uuidOr(NO_TEAM)),
    category_id: multiFilter(z.uuid()),
    organization_id: multiFilter(z.uuid()),
    tag: multiFilter(z.string().trim().min(1).max(40)),
    sla_state: multiFilter(z.enum(SLA_STATES)),
    has_duplicate_suggestion: choiceFilter(['true']),
    created_between: dateRangeFilter(),
  },
})

export type TicketListParams = ReturnType<typeof ticketListSchema.parse>
export type TicketFilters = TicketListParams['filters']

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
      refetchInterval: 10_000,
    }),
  history: (tenantId: string, id: string) =>
    infiniteQueryOptions({
      queryKey: queryKeys.tickets.history(tenantId, id),
      initialPageParam: undefined as string | undefined,
      queryFn: ({ pageParam }) =>
        unwrapBody(
          api().GET('/tickets/{ticket}/history', {
            params: { path: { ticket: id }, query: { per_page: HISTORY_PAGE_SIZE, cursor: pageParam } },
          }),
        ),
      getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    }),
}

/** Categories belong to the Agent directory (it owns their CRUD); re-exported for the ticket forms. */
export { categoryQueries } from '@/features/agents'

export function createTicket(input: TicketInput): Promise<Ticket> {
  return unwrap(api().POST('/tickets', { body: input }))
}

export function updateTicket(
  id: string,
  input: components['schemas']['UpdateTicketRequest'],
): Promise<Ticket> {
  return unwrap(api().PATCH('/tickets/{ticket}', { params: { path: { ticket: id } }, body: input }))
}

export function transitionTicket(
  id: string,
  input: components['schemas']['TransitionTicketRequest'],
): Promise<Ticket> {
  return unwrap(api().POST('/tickets/{ticket}/transition', { params: { path: { ticket: id } }, body: input }))
}

export function overrideTicketPriority(
  id: string,
  input: components['schemas']['OverridePriorityRequest'],
): Promise<Ticket> {
  return unwrap(api().POST('/tickets/{ticket}/priority', { params: { path: { ticket: id } }, body: input }))
}

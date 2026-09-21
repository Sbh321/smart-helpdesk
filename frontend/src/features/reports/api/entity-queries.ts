import { infiniteQueryOptions, keepPreviousData, queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type EntityOverview = components['schemas']['EntityOverviewResource']
export type EntityChange = components['schemas']['EntityChangeResource']
export type AsOfView = components['schemas']['AsOfResource']

/** The records with an Entity 360 page (docs/04-domain/reporting.md §Entity 360). */
export const OVERVIEW_ENTITIES = [
  'tickets',
  'contacts',
  'organizations',
  'agents',
  'teams',
  'categories',
] as const
export type OverviewEntity = (typeof OVERVIEW_ENTITIES)[number]

/** The recorded table of each entity: the `{type}` of the history API. */
export const HISTORY_TYPE: Record<OverviewEntity, string> = {
  tickets: 'tickets',
  contacts: 'contacts',
  organizations: 'organizations',
  agents: 'agent_profiles',
  teams: 'teams',
  categories: 'categories',
}

function fetchOverview(entity: OverviewEntity, id: string) {
  const path = { params: { path: { id } } }
  switch (entity) {
    case 'tickets':
      return unwrap(api().GET('/tickets/{id}/overview', path))
    case 'contacts':
      return unwrap(api().GET('/contacts/{id}/overview', path))
    case 'organizations':
      return unwrap(api().GET('/organizations/{id}/overview', path))
    case 'agents':
      return unwrap(api().GET('/agents/{id}/overview', path))
    case 'teams':
      return unwrap(api().GET('/teams/{id}/overview', path))
    case 'categories':
      return unwrap(api().GET('/categories/{id}/overview', path))
  }
}

/** Overview, change log and as-of view (`GET /v1/{entity}/{id}/overview`, `/v1/history/{type}/{id}…`). */
export const entityQueries = {
  overview: (tenantId: string, entity: OverviewEntity, id: string) =>
    queryOptions({
      queryKey: queryKeys.entity360.overview(tenantId, entity, id),
      queryFn: () => fetchOverview(entity, id),
      staleTime: 30_000,
    }),
  changes: (tenantId: string, type: string, id: string) =>
    infiniteQueryOptions({
      queryKey: queryKeys.entity360.changes(tenantId, type, id),
      initialPageParam: undefined as string | undefined,
      queryFn: ({ pageParam }) =>
        unwrapBody(
          api().GET('/history/{type}/{id}', {
            params: { path: { type, id }, query: pageParam ? { cursor: pageParam } : {} },
          }),
        ),
      getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    }),
  asOf: (tenantId: string, type: string, id: string, at: string) =>
    queryOptions({
      queryKey: queryKeys.entity360.asOf(tenantId, type, id, at),
      queryFn: () =>
        unwrap(api().GET('/history/{type}/{id}/as-of', { params: { path: { type, id }, query: { at } } })),
      placeholderData: keepPreviousData,
    }),
}

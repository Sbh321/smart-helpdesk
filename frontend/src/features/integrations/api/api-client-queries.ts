import { queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type ApiClient = components['schemas']['ApiClientResource']
export type NewApiClient = components['schemas']['NewApiClientResource']
export type ApiScope = components['schemas']['ApiScopeResource']
export type CreateApiClientInput = components['schemas']['StoreApiClientRequest']

export const apiClientQueries = {
  list: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.apiClients.list(tenantId),
      queryFn: () => unwrap(api().GET('/api-clients')),
    }),
  scopes: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.apiClients.scopes(tenantId),
      queryFn: () => unwrap(api().GET('/api-clients/scopes')),
      staleTime: Number.POSITIVE_INFINITY,
    }),
}

export const createApiClient = (input: CreateApiClientInput) =>
  unwrap(api().POST('/api-clients', { body: input }))

export const revokeApiClient = (id: string) =>
  unwrap(api().POST('/api-clients/{apiClient}/revoke', { params: { path: { apiClient: id } } }))

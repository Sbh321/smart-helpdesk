import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, defineListSchema, multiFilter } from '@/lib/list-params'

export type Organization = components['schemas']['OrganizationResource']
export type OrganizationInput = components['schemas']['OrganizationRequest']
export type OrganizationTier = components['schemas']['OrganizationTier']

type OrganizationIndex = operations['organizations.index']
type OrganizationListQuery = NonNullable<OrganizationIndex['parameters']['query']>
export type OrganizationListPage = OrganizationIndex['responses'][200]['content']['application/json']

export const ORGANIZATION_TIERS = [
  'standard',
  'premium',
  'enterprise',
] as const satisfies readonly OrganizationTier[]

export const organizationListSchema = defineListSchema({
  sortFields: ['name', 'created_at'],
  defaultSort: 'name',
  filters: {
    tier: multiFilter(z.enum(ORGANIZATION_TIERS)),
  },
})

export function listOrganizations(query: ApiListQuery): Promise<OrganizationListPage> {
  // `filter[tier]` and `filter[tag]` are accepted by the API but missing from the generated query type.
  return unwrapBody(api().GET('/organizations', { params: { query: query as OrganizationListQuery } }))
}

const OPTION_PAGE_SIZE = 100
const SEARCH_PAGE_SIZE = 20

export const organizationQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.organizations.list(tenantId, query),
      queryFn: () => listOrganizations(query),
      placeholderData: keepPreviousData,
    }),
  detail: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.organizations.detail(tenantId, id),
      queryFn: () =>
        unwrap(api().GET('/organizations/{organization}', { params: { path: { organization: id } } })),
    }),
  // MVP-SHORTCUT: the contact list's organisation filter loads the first 100 organisations once; V1:
  // search inside the filter popup (`GET /v1/organizations?search=`), V1-FE-07.
  options: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.organizations.options(tenantId),
      queryFn: async () => {
        const body = await unwrapBody(
          api().GET('/organizations', { params: { query: { per_page: OPTION_PAGE_SIZE, sort: 'name' } } }),
        )
        return body.data.map((organization) => ({ value: organization.id, label: organization.name }))
      },
      staleTime: 60_000,
    }),
  /** The organisation picker of the contact form: name matches for what was typed. */
  search: (tenantId: string, q: string) =>
    queryOptions({
      queryKey: queryKeys.organizations.search(tenantId, q),
      queryFn: async () => {
        const search = q.trim()
        const body = await unwrapBody(
          api().GET('/organizations', {
            params: { query: { per_page: SEARCH_PAGE_SIZE, sort: 'name', ...(search ? { search } : {}) } },
          }),
        )
        return body.data
      },
      placeholderData: keepPreviousData,
      staleTime: 30_000,
    }),
}

export function createOrganization(input: OrganizationInput): Promise<Organization> {
  return unwrap(api().POST('/organizations', { body: input }))
}

export function updateOrganization(id: string, input: OrganizationInput): Promise<Organization> {
  return unwrap(
    api().PATCH('/organizations/{organization}', { params: { path: { organization: id } }, body: input }),
  )
}

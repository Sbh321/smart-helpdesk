import { infiniteQueryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, dateRangeFilter, defineListSchema, multiFilter } from '@/lib/list-params'

export type AuditEntry = components['schemas']['AuditLogResource']
export type AuditActorType = components['schemas']['ActorType']
type AuditQuery = NonNullable<operations['audit-logs.index']['parameters']['query']>

const UUID = z.uuid()

/** Actor types an entry of a workspace can carry; platform entries never reach a workspace. */
export const AUDIT_ACTOR_TYPES = ['user', 'api_client', 'system'] as const

/**
 * The audit log's URL state (docs/07-api/pagination-filtering.md §Filtering). The API's `filter[...]`
 * names; `created_between` in the workspace zone. The feed has one fixed order (newest first).
 */
export const auditListSchema = defineListSchema({
  sortFields: ['created_at'] as const,
  defaultSort: '-created_at',
  filters: {
    action: multiFilter(z.string().regex(/^[a-z_]+(\.([a-z_]+|\*))?$/)),
    actor_type: multiFilter(z.enum(AUDIT_ACTOR_TYPES)),
    actor_id: multiFilter(UUID),
    subject_type: multiFilter(z.string().regex(/^[a-z_]+$/)),
    subject_id: multiFilter(UUID),
    created_between: dateRangeFilter(),
  },
})

/** The list query without what a cursor feed refuses (`page`, `sort`, `search`). */
export function auditApiQuery(query: ApiListQuery): AuditQuery {
  const { page: _page, sort: _sort, search: _search, ...rest } = query
  return rest as AuditQuery
}

export const auditQueries = {
  /** Newest first; each page's `meta.next_cursor` fetches the next older page. */
  list: (tenantId: string, query: AuditQuery) =>
    infiniteQueryOptions({
      queryKey: queryKeys.audit.list(tenantId, query),
      initialPageParam: undefined as string | undefined,
      queryFn: ({ pageParam }) =>
        unwrapBody(api().GET('/audit-logs', { params: { query: { ...query, cursor: pageParam } } })),
      getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    }),
}

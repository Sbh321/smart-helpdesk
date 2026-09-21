import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, defineListSchema, multiFilter } from '@/lib/list-params'

export type WorkspaceUser = components['schemas']['WorkspaceUserResource']
export type UserStatus = WorkspaceUser['status']
export type InviteUserInput = components['schemas']['InviteUserRequest']
export type UpdateUserInput = components['schemas']['UpdateUserRequest']
export type Role = components['schemas']['RoleResource']
export type RoleInput = components['schemas']['RoleRequest']
export type PermissionName = RoleInput['permissions'][number]
/** `GET /v1/permissions`: resource → actions; a permission's name is `resource.action`. */
export type PermissionCatalogue = Record<string, readonly string[]>

type UserListQuery = NonNullable<operations['users.index']['parameters']['query']>

export const USER_STATUSES = ['active', 'invited', 'disabled'] as const satisfies readonly UserStatus[]

/**
 * The five global default roles. Without `roles.manage` the role picker cannot read `GET /v1/roles`, so
 * it offers these names.
 */
export const DEFAULT_ROLE_NAMES = ['owner', 'admin', 'manager', 'agent', 'developer'] as const

export const userListSchema = defineListSchema({
  sortFields: ['name', 'email', 'created_at', 'last_login_at'] as const,
  defaultSort: 'name',
  filters: {
    status: multiFilter(z.enum(USER_STATUSES)),
    role: multiFilter(z.string().trim().min(1).max(64)),
  },
})

export const userQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.users.list(tenantId, query),
      queryFn: () => unwrapBody(api().GET('/users', { params: { query: query as UserListQuery } })),
      placeholderData: keepPreviousData,
    }),
}

export const roleQueries = {
  list: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.roles.list(tenantId),
      queryFn: () => unwrap(api().GET('/roles')),
    }),
  permissions: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.roles.permissions(tenantId),
      queryFn: async (): Promise<PermissionCatalogue> => unwrap(api().GET('/permissions')),
      staleTime: Number.POSITIVE_INFINITY,
    }),
}

export const inviteUser = (input: InviteUserInput) =>
  unwrap(api().POST('/users/invitations', { body: input }))
export const updateUser = (id: string, input: UpdateUserInput) =>
  unwrap(api().PATCH('/users/{user}', { params: { path: { user: id } }, body: input }))
export const resendInvitation = (id: string) =>
  unwrap(api().POST('/users/{user}/invitation', { params: { path: { user: id } } }))
export const disableUser = (id: string) =>
  unwrap(api().POST('/users/{user}/disable', { params: { path: { user: id } } }))
export const enableUser = (id: string) =>
  unwrap(api().POST('/users/{user}/enable', { params: { path: { user: id } } }))

export const createRole = (input: RoleInput) => unwrap(api().POST('/roles', { body: input }))
export const updateRole = (id: string, input: RoleInput) =>
  unwrap(api().PATCH('/roles/{role}', { params: { path: { role: id } }, body: input }))
export const deleteRole = (id: string) =>
  unwrapBody(api().DELETE('/roles/{role}', { params: { path: { role: id } } }))

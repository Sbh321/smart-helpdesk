import { z } from 'zod'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'

const userRules = copy.users.validation
const roleRules = copy.roles.validation

/** Mirrors `InviteUserRequest`; the unique email stays with the API (422 on `email`). */
export const inviteUserFormSchema = z.object({
  name: z.string().trim().min(2, userRules.name).max(120, userRules.name),
  email: z.string().trim().max(190, userRules.emailLength).pipe(z.email(userRules.email)),
  roles: z.array(z.string()).min(1, userRules.roles).max(10, userRules.rolesMax),
})
export type InviteUserFormValues = z.input<typeof inviteUserFormSchema>

/** Mirrors `UpdateUserRequest`; the form always sends both fields. */
export const editUserFormSchema = z.object({
  name: z.string().trim().min(2, userRules.name).max(120, userRules.name),
  roles: z.array(z.string()).min(1, userRules.roles).max(10, userRules.rolesMax),
})
export type EditUserFormValues = z.input<typeof editUserFormSchema>

export const ROLE_NAME = /^[a-z][a-z0-9-]*$/

/**
 * Mirrors `RoleRequest` (name unique per workspace, checked by the API). The API accepts an empty
 * permission list; the editor asks for at least one, since a role without permissions grants nothing.
 */
export const roleFormSchema = z.object({
  name: z.string().trim().min(1, roleRules.name).max(64, roleRules.name).regex(ROLE_NAME, roleRules.name),
  permissions: z.array(z.string()).min(1, roleRules.permissions),
})
export type RoleFormValues = z.input<typeof roleFormSchema>

/** "tickets.assign" → "Assign tickets"; an unknown name reads "Tickets: assign". */
export function permissionLabel(name: string): string {
  const known = copy.roles.permissionLabels[name]
  if (known) return known
  const [resource = name, action = ''] = name.split('.')
  const group = groupLabel(resource)
  return action ? `${group}: ${action.replace(/_/g, ' ')}` : group
}

/** "tickets" → "Tickets"; an unknown resource is capitalised. */
export function groupLabel(resource: string): string {
  return (
    copy.roles.groups[resource] ?? resource.charAt(0).toUpperCase() + resource.slice(1).replace(/_/g, ' ')
  )
}

/** The five default roles have display names; a custom role is shown by its own name. */
export function roleLabel(name: string): string {
  return copy.roles.defaultNames[name] ?? name
}

/** Permission names of one catalogue group, in catalogue order. */
export function groupPermissions(resource: string, actions: readonly string[]): string[] {
  return actions.map((action) => `${resource}.${action}`)
}

/** A group checkbox's state: every, some or none of its permissions selected. */
export function groupState(selected: readonly string[], names: readonly string[]): 'all' | 'some' | 'none' {
  const count = names.filter((name) => selected.includes(name)).length
  return count === 0 ? 'none' : count === names.length ? 'all' : 'some'
}

/** Selects or clears a whole group, keeping the other selections and their order. */
export function toggleGroup(selected: readonly string[], names: readonly string[], on: boolean): string[] {
  const rest = selected.filter((name) => !names.includes(name))
  return on ? [...rest, ...names] : rest
}

/**
 * 403 `forbidden` with `meta.roles` (a role or permission beyond the actor's reach): the problem detail
 * and the names it lists, for the message beside the roles or permissions; `undefined` for any other
 * failure.
 */
export function beyondReachMessage(error: unknown, label: (name: string) => string): string | undefined {
  if (!isApiError(error) || error.code !== 'forbidden' || !Array.isArray(error.meta?.roles)) return undefined
  const names = error.meta.roles.filter((name): name is string => typeof name === 'string')
  const detail = error.detail ?? error.title
  return names.length > 0
    ? fill(copy.users.forbiddenRoles, { detail, roles: names.map(label).join(', ') })
    : detail
}

/** 409 `in_use` on deleting a role: how many users still hold it; `undefined` for any other failure. */
export function roleHolders(error: unknown): number | undefined {
  if (!isApiError(error) || error.code !== 'in_use') return undefined
  const users = error.meta?.users
  return typeof users === 'number' ? users : undefined
}

import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { db, NOW, nextId, PERMISSION_CATALOGUE, SESSION_USER_ID, type WorkspaceUserResource } from './data'
import { apiUrl, problem } from './handlers'
import { paginate, sortRows, validateListQuery, validationFailed } from './list'

type InviteInput = components['schemas']['InviteUserRequest']
type UpdateInput = components['schemas']['UpdateUserRequest']
type RoleInput = components['schemas']['RoleRequest']

const OWNER = 'owner'
const INVITATION_HOURS = 48
const ROLE_NAME = /^[a-z][a-z0-9-]*$/
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const notFound = () => problem(404, 'not_found', { title: 'Not found' })

/** The signed-in user is the session fixture's user; tests change their roles in `db.users`. */
function actor(): WorkspaceUserResource | undefined {
  return db.users.find((user) => user.id === SESSION_USER_ID)
}

function permissionsOf(roleNames: readonly string[]): string[] {
  return [
    ...new Set(db.roles.filter((role) => roleNames.includes(role.name)).flatMap((role) => role.permissions)),
  ]
}

function changed(before: readonly string[], after: readonly string[]): string[] {
  return [
    ...new Set([
      ...after.filter((name) => !before.includes(name)),
      ...before.filter((name) => !after.includes(name)),
    ]),
  ]
}

/** RoleAssignmentGuard::ensureCanChange: only an owner touches `owner`; no role beyond the actor's reach. */
function roleGuard(before: readonly string[], after: readonly string[]): Response | undefined {
  const names = changed(before, after)
  if (names.length === 0) return undefined
  const roles = actor()?.roles ?? []
  if (names.includes(OWNER) && !roles.includes(OWNER)) {
    return problem(403, 'forbidden', {
      title: 'Forbidden',
      detail: 'Only an owner can give or take the owner role.',
      meta: { roles: [OWNER] },
    })
  }
  const held = permissionsOf(roles)
  const beyond = db.roles
    .filter((role) => names.includes(role.name))
    .filter((role) => role.permissions.some((permission) => !held.includes(permission)))
    .map((role) => role.name)
  if (beyond.length > 0) {
    return problem(403, 'forbidden', {
      title: 'Forbidden',
      detail: 'You can only give or take roles whose permissions you hold yourself.',
      meta: { roles: beyond },
    })
  }
  return undefined
}

function roleNameErrors(roles: unknown): Record<string, string[]> {
  if (!Array.isArray(roles) || roles.length === 0) return { roles: ['The roles field is required.'] }
  const errors: Record<string, string[]> = {}
  roles.forEach((name, index) => {
    if (!db.roles.some((role) => role.name === name))
      errors[`roles.${index}`] = ['This role does not exist in the workspace.']
  })
  return errors
}

function activeOwners(): WorkspaceUserResource[] {
  return db.users.filter((user) => user.status === 'active' && user.roles.includes(OWNER))
}

function lastOwner(): Response {
  return problem(422, 'last_owner', {
    title: 'Last owner',
    detail: 'This workspace must keep at least one active owner.',
  })
}

function conflict(reason: string, detail: string): Response {
  return problem(409, 'conflict', { title: 'Conflict', detail, meta: { reason } })
}

function invitationExpiry(): string {
  return new Date(Date.parse(NOW) + INVITATION_HOURS * 3_600_000).toISOString()
}

function findUser(id: unknown) {
  return db.users.find((user) => user.id === String(id))
}

/** RoleAssignmentGuard::ensureCanEditPermissions: a permission is added or removed only by a holder. */
function permissionGuard(before: readonly string[], after: readonly string[]): Response | undefined {
  const held = permissionsOf(actor()?.roles ?? [])
  const beyond = changed(before, after).filter((permission) => !held.includes(permission))
  return beyond.length > 0
    ? problem(403, 'forbidden', {
        title: 'Forbidden',
        detail: 'You can only add or remove permissions you hold yourself.',
        meta: { roles: beyond },
      })
    : undefined
}

function roleErrors(body: Partial<RoleInput>, ignoreId?: string): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  const name = body.name
  if (typeof name !== 'string' || name.length > 64 || !ROLE_NAME.test(name)) {
    errors.name = ['The name field format is invalid.']
  } else if (db.roles.some((role) => role.name === name && role.id !== ignoreId)) {
    errors.name = ['The name has already been taken.']
  }
  const known = Object.entries(PERMISSION_CATALOGUE).flatMap(([resource, actions]) =>
    actions.map((action) => `${resource}.${action}`),
  )
  ;(body.permissions ?? []).forEach((permission, index) => {
    if (!known.includes(permission)) errors[`permissions.${index}`] = ['The selected permission is invalid.']
  })
  return errors
}

/** `/v1/users…`, `/v1/roles…` and `/v1/permissions` (M1-09, M2-12) over the in-memory workspace. */
export const userHandlers = [
  http.get(apiUrl('/users'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: ['name', 'email', 'created_at', 'last_login_at'],
      filters: ['status', 'role'],
      defaultSort: 'name',
    })
    if (invalid) return invalid
    let rows = [...db.users]
    const search = url.searchParams.get('search')?.trim().toLowerCase()
    if (search) rows = rows.filter((user) => `${user.name} ${user.email}`.toLowerCase().includes(search))
    const statuses = url.searchParams.get('filter[status]')?.split(',')
    if (statuses) rows = rows.filter((user) => statuses.includes(user.status))
    const roles = url.searchParams.get('filter[role]')?.split(',')
    if (roles) rows = rows.filter((user) => user.roles.some((role) => roles.includes(role)))
    return HttpResponse.json(paginate(url, sortRows(rows, url.searchParams.get('sort') ?? 'name')))
  }),
  http.post(apiUrl('/users/invitations'), async ({ request }) => {
    const body = (await request.json()) as Partial<InviteInput>
    const errors: Record<string, string[]> = {}
    const name = typeof body.name === 'string' ? body.name.trim() : ''
    if (name.length < 2 || name.length > 120) errors.name = ['The name field must be at least 2 characters.']
    const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : ''
    if (!EMAIL.test(email)) errors.email = ['The email field must be a valid email address.']
    else if (db.users.some((user) => user.email.toLowerCase() === email))
      errors.email = ['A user with this email address is already in the workspace.']
    Object.assign(errors, roleNameErrors(body.roles))
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const roles = body.roles ?? []
    const refused = roleGuard([], roles)
    if (refused) return refused
    const user: WorkspaceUserResource = {
      id: nextId(50),
      name,
      email,
      status: 'invited',
      roles,
      invitation_expires_at: invitationExpiry(),
      invitation_expired: false,
      last_login_at: null,
      created_at: NOW,
    }
    db.users.push(user)
    return HttpResponse.json({ data: user }, { status: 201 })
  }),
  http.get(apiUrl('/users/{user}'), ({ params }) => {
    const user = findUser(params.user)
    return user ? HttpResponse.json({ data: user }) : notFound()
  }),
  http.patch(apiUrl('/users/{user}'), async ({ params, request }) => {
    const user = findUser(params.user)
    if (!user) return notFound()
    const body = (await request.json()) as UpdateInput
    const errors: Record<string, string[]> = {}
    if (body.name !== undefined && (body.name.trim().length < 2 || body.name.trim().length > 120))
      errors.name = ['The name field must be at least 2 characters.']
    if (body.roles !== undefined) Object.assign(errors, roleNameErrors(body.roles))
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    if (body.roles !== undefined) {
      const refused = roleGuard(user.roles, body.roles)
      if (refused) return refused
      const losesOwner = user.roles.includes(OWNER) && !body.roles.includes(OWNER)
      if (losesOwner && user.status === 'active' && activeOwners().length === 1) return lastOwner()
      user.roles = body.roles
    }
    if (body.name !== undefined) user.name = body.name.trim()
    return HttpResponse.json({ data: user })
  }),
  http.post(apiUrl('/users/{user}/invitation'), ({ params }) => {
    const user = findUser(params.user)
    if (!user) return notFound()
    if (user.status === 'active') return conflict('already_accepted', 'This invitation was already accepted.')
    if (user.status === 'disabled') return conflict('disabled', 'This user is disabled.')
    user.invitation_expires_at = invitationExpiry()
    user.invitation_expired = false
    return HttpResponse.json({ data: user })
  }),
  http.post(apiUrl('/users/{user}/disable'), ({ params }) => {
    const user = findUser(params.user)
    if (!user) return notFound()
    if (user.id === SESSION_USER_ID) return conflict('self', 'You cannot disable your own account.')
    if (user.status === 'active' && user.roles.includes(OWNER) && activeOwners().length === 1)
      return lastOwner()
    user.status = 'disabled'
    return HttpResponse.json({ data: user })
  }),
  http.post(apiUrl('/users/{user}/enable'), ({ params }) => {
    const user = findUser(params.user)
    if (!user) return notFound()
    user.status = 'active'
    return HttpResponse.json({ data: user })
  }),
  http.get(apiUrl('/roles'), () => HttpResponse.json({ data: db.roles })),
  http.get(apiUrl('/permissions'), () => HttpResponse.json({ data: PERMISSION_CATALOGUE })),
  http.post(apiUrl('/roles'), async ({ request }) => {
    const body = (await request.json()) as Partial<RoleInput>
    const errors = roleErrors(body)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const permissions = body.permissions ?? []
    const refused = permissionGuard([], permissions)
    if (refused) return refused
    const role = { id: nextId(51), name: String(body.name), is_global: false, is_system: false, permissions }
    db.roles.push(role)
    return HttpResponse.json({ data: role }, { status: 201 })
  }),
  http.patch(apiUrl('/roles/{role}'), async ({ params, request }) => {
    const role = db.roles.find((row) => row.id === String(params.role))
    if (!role || role.is_global) return notFound()
    const body = (await request.json()) as Partial<RoleInput>
    const errors = roleErrors({ name: body.name ?? role.name, permissions: body.permissions ?? [] }, role.id)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    if (body.permissions) {
      const refused = permissionGuard(role.permissions, body.permissions)
      if (refused) return refused
      role.permissions = body.permissions
    }
    if (body.name) {
      const previous = role.name
      role.name = body.name
      for (const user of db.users)
        user.roles = user.roles.map((name) => (name === previous ? (body.name ?? name) : name))
    }
    return HttpResponse.json({ data: role })
  }),
  http.delete(apiUrl('/roles/{role}'), ({ params }) => {
    const role = db.roles.find((row) => row.id === String(params.role))
    if (!role || role.is_global) return notFound()
    const holders = db.users.filter((user) => user.roles.includes(role.name)).length
    if (holders > 0)
      return problem(409, 'in_use', {
        title: 'In use',
        detail: 'Users still hold this role. Give them another role first.',
        meta: { users: holders },
      })
    db.roles = db.roles.filter((row) => row.id !== role.id)
    return new HttpResponse(null, { status: 204 })
  }),
]

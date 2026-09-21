import { describe, expect, it } from 'vitest'
import { copy } from '@/copy/en'
import { ApiError } from '@/lib/api/errors'
import {
  beyondReachMessage,
  editUserFormSchema,
  groupLabel,
  groupPermissions,
  groupState,
  inviteUserFormSchema,
  permissionLabel,
  roleFormSchema,
  roleHolders,
  roleLabel,
  toggleGroup,
} from './schemas'

const userRules = copy.users.validation
const roleRules = copy.roles.validation

function messages(result: {
  success: boolean
  error?: { issues: { path: PropertyKey[]; message: string }[] }
}) {
  return Object.fromEntries(
    (result.error?.issues ?? []).map((issue) => [issue.path.join('.'), issue.message]),
  )
}

describe('inviteUserFormSchema', () => {
  it('accepts a name, an email and at least one role, trimmed', () => {
    expect(
      inviteUserFormSchema.parse({ name: ' Nima Lama ', email: ' nima@acme.test ', roles: ['agent'] }),
    ).toEqual({ name: 'Nima Lama', email: 'nima@acme.test', roles: ['agent'] })
  })

  it('needs a name of 2 to 120 characters, a valid email and a role', () => {
    expect(messages(inviteUserFormSchema.safeParse({ name: ' N ', email: 'nope', roles: [] }))).toEqual({
      name: userRules.name,
      email: userRules.email,
      roles: userRules.roles,
    })
    expect(
      messages(
        inviteUserFormSchema.safeParse({
          name: 'x'.repeat(121),
          email: `${'x'.repeat(190)}@acme.test`,
          roles: Array.from({ length: 11 }, (_, index) => `role-${index}`),
        }),
      ),
    ).toEqual({ name: userRules.name, email: userRules.emailLength, roles: userRules.rolesMax })
  })
})

describe('editUserFormSchema', () => {
  it('keeps at least one role', () => {
    expect(editUserFormSchema.safeParse({ name: 'Asha Rai', roles: ['agent'] }).success).toBe(true)
    expect(messages(editUserFormSchema.safeParse({ name: 'Asha Rai', roles: [] }))).toEqual({
      roles: userRules.roles,
    })
  })
})

describe('roleFormSchema', () => {
  it('mirrors the API name rule and asks for a permission', () => {
    expect(roleFormSchema.safeParse({ name: 'billing-lead', permissions: ['tickets.view'] }).success).toBe(
      true,
    )
    for (const name of ['', 'Billing', '1st-line', 'night shift', `a${'b'.repeat(64)}`]) {
      expect(messages(roleFormSchema.safeParse({ name, permissions: [] }))).toEqual({
        name: roleRules.name,
        permissions: roleRules.permissions,
      })
    }
  })
})

describe('labels', () => {
  it('words known permissions and falls back to a readable label', () => {
    expect(permissionLabel('tickets.assign')).toBe('Assign tickets')
    expect(permissionLabel('reports.export_csv')).toBe('Reports: export csv')
    expect(permissionLabel('billing_plans.view')).toBe('Billing plans: view')
    expect(groupLabel('sla')).toBe('SLA')
    expect(roleLabel('owner')).toBe('Owner')
    expect(roleLabel('billing-lead')).toBe('billing-lead')
  })
})

describe('permission groups', () => {
  const tickets = groupPermissions('tickets', ['view', 'assign'])

  it('names a group by resource.action', () => {
    expect(tickets).toEqual(['tickets.view', 'tickets.assign'])
  })

  it('tells whether all, some or none of a group is selected', () => {
    expect(groupState([], tickets)).toBe('none')
    expect(groupState(['tickets.view'], tickets)).toBe('some')
    expect(groupState(['tickets.assign', 'tickets.view', 'media.view'], tickets)).toBe('all')
  })

  it('selects and clears a whole group without touching the others', () => {
    expect(toggleGroup(['media.view', 'tickets.view'], tickets, true)).toEqual([
      'media.view',
      'tickets.view',
      'tickets.assign',
    ])
    expect(toggleGroup(['media.view', 'tickets.view'], tickets, false)).toEqual(['media.view'])
  })
})

describe('problem helpers', () => {
  const forbidden = new ApiError({
    status: 403,
    code: 'forbidden',
    title: 'Forbidden',
    detail: 'Only an owner can give or take the owner role.',
    meta: { roles: ['owner'] },
  })

  it('explains a 403 forbidden with meta.roles and ignores other failures', () => {
    expect(beyondReachMessage(forbidden, roleLabel)).toBe(
      'Only an owner can give or take the owner role. (Owner)',
    )
    expect(
      beyondReachMessage(new ApiError({ status: 403, code: 'forbidden', title: 'Forbidden' }), roleLabel),
    ).toBeUndefined()
    expect(beyondReachMessage(new Error('boom'), roleLabel)).toBeUndefined()
  })

  it('reads the holders of a role from 409 in_use', () => {
    expect(
      roleHolders(new ApiError({ status: 409, code: 'in_use', title: 'In use', meta: { users: 3 } })),
    ).toBe(3)
    expect(roleHolders(forbidden)).toBeUndefined()
  })
})

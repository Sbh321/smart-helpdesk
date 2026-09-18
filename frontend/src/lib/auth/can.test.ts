import { describe, expect, it } from 'vitest'
import { hasAllPermissions, hasAnyPermission, hasPermission, permissionsOf } from './can'
import type { Session } from './session'

const session = (permissions: string[]): Session => ({ permissions }) as unknown as Session

describe('hasPermission', () => {
  it('denies every permission while the list is empty (the state until roadmap M1-09)', () => {
    expect(hasPermission([], 'tickets.view')).toBe(false)
    expect(hasPermission([], 'tickets.assign')).toBe(false)
  })

  it('denies when the session is missing entirely', () => {
    expect(hasPermission(undefined, 'tickets.view')).toBe(false)
    expect(hasPermission(null, 'tickets.view')).toBe(false)
  })

  it('grants exactly the permissions the API listed', () => {
    const granted = ['tickets.view', 'comments.internal']
    expect(hasPermission(granted, 'tickets.view')).toBe(true)
    expect(hasPermission(granted, 'comments.internal')).toBe(true)
    expect(hasPermission(granted, 'tickets.assign')).toBe(false)
  })

  it('does not treat a prefix or a wildcard as a grant', () => {
    expect(hasPermission(['tickets.view'], 'tickets')).toBe(false)
    expect(hasPermission(['tickets.*'], 'tickets.view')).toBe(false)
    expect(hasPermission(['*'], 'tickets.view')).toBe(false)
  })

  it('denies the empty permission name', () => {
    expect(hasPermission(['tickets.view'], '')).toBe(false)
  })
})

describe('hasAnyPermission / hasAllPermissions', () => {
  const granted = ['tickets.view', 'contacts.view']

  it('any: true when at least one is granted, false for an empty requirement', () => {
    expect(hasAnyPermission(granted, ['tickets.assign', 'tickets.view'])).toBe(true)
    expect(hasAnyPermission(granted, ['tickets.assign'])).toBe(false)
    expect(hasAnyPermission(granted, [])).toBe(false)
  })

  it('all: true only when every one is granted; an empty requirement is granted', () => {
    expect(hasAllPermissions(granted, ['tickets.view', 'contacts.view'])).toBe(true)
    expect(hasAllPermissions(granted, ['tickets.view', 'tickets.assign'])).toBe(false)
    expect(hasAllPermissions(granted, [])).toBe(true)
  })
})

describe('permissionsOf', () => {
  it('is an empty list without a session', () => {
    expect(permissionsOf(null)).toEqual([])
    expect(permissionsOf(undefined)).toEqual([])
  })

  it('is the session list when there is one', () => {
    expect(permissionsOf(session(['tickets.view']))).toEqual(['tickets.view'])
  })
})

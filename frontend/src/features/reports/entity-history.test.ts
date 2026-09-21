import { describe, expect, it } from 'vitest'
import {
  actorLabel,
  attributeLabel,
  canViewHistory,
  formatAttributeValue,
  type LifecycleInterval,
  lifecycleShares,
  shortId,
} from './entity-history'

const ID = '019a0002-0000-7000-8000-000000000002'

describe('canViewHistory', () => {
  it('follows the history API: tickets.view, the subject permission, then history.view', () => {
    const manager = ['tickets.view', 'contacts.view', 'agents.view', 'history.view']
    expect(canViewHistory('contacts', manager)).toBe(true)
    expect(canViewHistory('agents', manager)).toBe(true)
    expect(canViewHistory('contacts', ['contacts.view', 'history.view'])).toBe(false)
    expect(canViewHistory('agents', ['tickets.view', 'history.view'])).toBe(false)
  })

  it('opens only the ticket family to agents with internal comments', () => {
    const agent = ['tickets.view', 'contacts.view', 'comments.internal']
    expect(canViewHistory('tickets', agent)).toBe(true)
    expect(canViewHistory('contacts', agent)).toBe(false)
    expect(canViewHistory('tickets', ['tickets.view'])).toBe(false)
  })
})

describe('attributeLabel', () => {
  it('names known columns and sentence-cases the rest', () => {
    expect(attributeLabel('organization_id')).toBe('Organisation')
    expect(attributeLabel('some_new_column')).toBe('Some new column')
    expect(attributeLabel('owner_id')).toBe('Owner')
  })
})

describe('formatAttributeValue', () => {
  const names = { organization: (id: string) => (id === ID ? 'Globex' : undefined) }

  it('names known relations and shortens unknown ids', () => {
    expect(formatAttributeValue('organization_id', ID, 'UTC', names)).toBe('Globex')
    expect(formatAttributeValue('organization_id', ID, 'UTC')).toBe(shortId(ID))
    expect(formatAttributeValue('duplicate_of_id', ID, 'UTC')).toBe('…00000002')
  })

  it('labels enumerations, instants, durations, booleans and empty values', () => {
    expect(formatAttributeValue('status', 'in_progress', 'UTC')).toBe('In progress')
    expect(formatAttributeValue('tier', 'enterprise', 'UTC')).toBe('Enterprise')
    expect(formatAttributeValue('resolved_at', '2026-09-17T10:00:00+00:00', 'Asia/Kathmandu')).toBe(
      '17 Sep 2026, 15:45:00',
    )
    expect(formatAttributeValue('paused_total_seconds', 5400, 'UTC')).toBe('1h 30m')
    expect(formatAttributeValue('is_active', false, 'UTC')).toBe('No')
    expect(formatAttributeValue('phone', null, 'UTC')).toBe('Empty')
    expect(formatAttributeValue('metadata', { a: 1, b: 2 }, 'UTC')).toBe('2 values')
    expect(formatAttributeValue('name', 'Globex', 'UTC')).toBe('Globex')
  })
})

describe('actorLabel', () => {
  it('says who made a change', () => {
    expect(actorLabel('user', 'me', 'me')).toBe('You')
    expect(actorLabel('user', ID, 'me', { user: () => 'Asha Rai' })).toBe('Asha Rai')
    expect(actorLabel('user', ID, 'me')).toBe('User …00000002')
    expect(actorLabel('api_client', ID, 'me')).toBe('API client …00000002')
    expect(actorLabel('system', null, 'me')).toBe('System')
    expect(actorLabel(null, null, 'me')).toBe('Unknown')
  })
})

describe('lifecycleShares', () => {
  const interval = (seq: number, wall: number): LifecycleInterval => ({
    seq,
    status: 'open',
    assigned_agent_id: null,
    team_id: null,
    priority_level: 'P3',
    starts_at: '2026-09-17T09:00:00Z',
    ends_at: null,
    wall_seconds: wall,
    business_seconds: null,
    open: false,
  })

  it('gives each interval its share of the whole trace', () => {
    expect(lifecycleShares([interval(1, 3600), interval(2, 7200), interval(3, 1800 * 2)])).toEqual([
      25, 50, 25,
    ])
    expect(lifecycleShares([interval(1, 0)])).toEqual([0])
  })
})

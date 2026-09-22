import { describe, expect, test } from 'vitest'
import type { TicketEvent } from './api/ticket-queries'
import { groupTicketEvents, ticketEventKind } from './ticket-timeline'

let n = 0
function event(type: string, at: string, actor: string | null = 'u1', actorType = 'user'): TicketEvent {
  n += 1
  return {
    id: `e${n}`,
    type,
    actor_type: actorType,
    actor_id: actor,
    old_values: {},
    new_values: {},
    note: null,
    created_at: at,
  }
}

describe('groupTicketEvents', () => {
  test('groups consecutive events by the same actor within five minutes, newest first', () => {
    const feed = [
      event('assigned', '2026-09-20T10:04:00Z'),
      event('priority_changed', '2026-09-20T10:01:00Z'),
      event('created', '2026-09-20T10:00:00Z'),
      event('sla_warning', '2026-09-20T09:59:00Z', null, 'system'),
      event('status_changed', '2026-09-20T09:00:00Z'),
    ]
    const groups = groupTicketEvents(feed)
    expect(groups.map((group) => group.events.map((item) => item.type))).toEqual([
      ['assigned', 'priority_changed', 'created'],
      ['sla_warning'],
      ['status_changed'],
    ])
  })

  test("starts a new group after the window, measured from the group's newest event", () => {
    const groups = groupTicketEvents([
      event('edited', '2026-09-20T10:10:00Z'),
      event('edited', '2026-09-20T10:06:00Z'),
      event('edited', '2026-09-20T10:04:00Z'),
    ])
    expect(groups.map((group) => group.events.length)).toEqual([2, 1])
  })

  test('keeps different users apart', () => {
    const groups = groupTicketEvents([
      event('edited', '2026-09-20T10:00:00Z', 'u1'),
      event('edited', '2026-09-20T10:00:00Z', 'u2'),
    ])
    expect(groups).toHaveLength(2)
  })
})

test('maps event types to icon kinds', () => {
  expect(ticketEventKind('created')).toBe('created')
  expect(ticketEventKind('reopened')).toBe('status')
  expect(ticketEventKind('priority_overridden')).toBe('priority')
  expect(ticketEventKind('unassigned')).toBe('assignment')
  expect(ticketEventKind('sla_breached')).toBe('sla')
  expect(ticketEventKind('duplicate_marked')).toBe('duplicate')
  expect(ticketEventKind('comment_added')).toBe('comment')
  expect(ticketEventKind('attachment_added')).toBe('attachment')
  expect(ticketEventKind('tags_changed')).toBe('edit')
})

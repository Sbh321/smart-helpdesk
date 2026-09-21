import { describe, expect, it } from 'vitest'
import { ticketListSchema } from '../api/ticket-queries'
import { activeQuickView } from './ticket-quick-views'

const view = (search: Record<string, unknown>) => activeQuickView(ticketListSchema.parse(search))?.id

describe('activeQuickView', () => {
  it('derives the view from the URL alone', () => {
    expect(view({})).toBe('all')
    expect(view({ sort: 'number' })).toBe('all')
    expect(view({ assignee_id: 'me', status: 'active' })).toBe('mine')
    expect(view({ assignee_id: 'unassigned', status: 'active' })).toBe('unassigned')
    expect(view({ sla_state: 'breached,warning', sort: 'sla_due_at' })).toBe('breaching')
  })

  it('is undefined for a hand-made combination', () => {
    expect(view({ assignee_id: 'me' })).toBeUndefined()
    expect(view({ assignee_id: 'me', status: 'active', priority: 'P1' })).toBeUndefined()
    expect(view({ sla_state: 'warning,breached' })).toBeUndefined()
    expect(view({ search: 'invoice' })).toBeUndefined()
  })
})

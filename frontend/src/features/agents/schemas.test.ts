import { describe, expect, it } from 'vitest'
import { agentFormSchema, agentShiftSchema, skillAssignmentSchema } from './schemas'

describe('agent schemas', () => {
  it('enforces capacity and skill levels', () => {
    expect(
      agentFormSchema.safeParse({
        user_id: crypto.randomUUID(),
        capacity: 0,
        availability: 'available',
        skills: [],
        team_ids: [],
      }).success,
    ).toBe(false)
    expect(skillAssignmentSchema.safeParse({ skill_id: crypto.randomUUID(), level: 6 }).success).toBe(false)
  })

  it('requires exactly one shift day and an increasing time range', () => {
    expect(
      agentShiftSchema.safeParse({
        weekday: 1,
        date: '2026-09-21',
        starts_at: '09:00',
        ends_at: '17:00',
        is_off: false,
      }).success,
    ).toBe(false)
    expect(
      agentShiftSchema.safeParse({
        weekday: 1,
        date: null,
        starts_at: '17:00',
        ends_at: '09:00',
        is_off: false,
      }).success,
    ).toBe(false)
  })
})

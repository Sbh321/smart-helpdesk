import { describe, expect, test } from 'vitest'
import { slaRemaining } from './sla-indicator'

const now = new Date('2026-09-18T09:00:00Z')

describe('slaRemaining (M4-07)', () => {
  test('a running or due-soon timer counts down in words', () => {
    expect(slaRemaining('running', '2026-09-18T12:00:00Z', now)).toBe('3 hours left')
    expect(slaRemaining('warning', '2026-09-18T09:20:00Z', now)).toBe('20 minutes left')
  })

  test('a breached timer, or one past due before the evaluator ran, reads as overdue', () => {
    expect(slaRemaining('breached', '2026-09-18T07:00:00Z', now)).toBe('2 hours overdue')
    expect(slaRemaining('running', '2026-09-18T08:45:00Z', now)).toBe('15 minutes overdue')
  })

  test('a paused or met timer never shows a countdown', () => {
    expect(slaRemaining('paused', '2026-09-18T12:00:00Z', now)).toBeNull()
    expect(slaRemaining('met', '2026-09-18T12:00:00Z', now)).toBeNull()
    expect(slaRemaining('cancelled', '2026-09-18T12:00:00Z', now)).toBeNull()
    expect(slaRemaining('running', null, now)).toBeNull()
  })
})

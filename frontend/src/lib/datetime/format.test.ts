import { describe, expect, it } from 'vitest'
import { durationBetween, formatInZone, isoToZonedInput, zonedInputToIso } from './format'

describe('formatInZone', () => {
  it('shows a UTC instant in the tenant time zone', () => {
    expect(formatInZone('2026-09-17T10:00:00Z', 'Asia/Kathmandu')).toBe('17 Sep 2026, 15:45')
  })

  it('does not depend on the machine time zone', () => {
    expect(formatInZone('2026-09-17T23:30:00Z', 'UTC', 'yyyy-MM-dd HH:mm')).toBe('2026-09-17 23:30')
    expect(formatInZone('2026-09-17T23:30:00Z', 'Asia/Kathmandu', 'yyyy-MM-dd HH:mm')).toBe(
      '2026-09-18 05:15',
    )
  })
})

describe('durationBetween', () => {
  it('describes the distance between two instants', () => {
    expect(durationBetween('2026-09-17T09:00:00Z', '2026-09-17T11:00:00Z')).toBe('2 hours')
  })
})

describe('zonedInputToIso and isoToZonedInput', () => {
  it('reads a date-time input as a wall time in the workspace zone', () => {
    expect(zonedInputToIso('2026-09-17T15:45', 'Asia/Kathmandu')).toBe('2026-09-17T10:00:00.000Z')
    expect(zonedInputToIso('2026-09-17T15:45:30', 'UTC')).toBe('2026-09-17T15:45:30.000Z')
  })

  it('rejects incomplete values', () => {
    expect(zonedInputToIso('', 'UTC')).toBeNull()
    expect(zonedInputToIso('2026-09-17', 'UTC')).toBeNull()
  })

  it('round-trips to the second', () => {
    expect(isoToZonedInput('2026-09-17T10:00:59Z', 'Asia/Kathmandu')).toBe('2026-09-17T15:45:59')
  })
})

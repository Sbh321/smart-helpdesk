import { describe, expect, it } from 'vitest'
import { durationBetween, formatInZone } from './format'

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

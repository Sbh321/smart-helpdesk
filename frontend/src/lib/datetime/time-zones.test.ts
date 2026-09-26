import { describe, expect, test } from 'vitest'
import { canonicalTimeZone, timeZoneNames, timeZoneOffset } from './time-zones'

describe('time zones', () => {
  test('the list is the API list with UTC first, and has no old names', () => {
    const names = timeZoneNames()
    expect(names[0]).toBe('UTC')
    expect(names).toContain('Asia/Kathmandu')
    expect(names).not.toContain('Asia/Katmandu')
    expect(new Set(names).size).toBe(names.length)
  })

  test('an old name the browser reports becomes the current one; an unknown zone is null', () => {
    expect(canonicalTimeZone('Asia/Katmandu')).toBe('Asia/Kathmandu')
    expect(canonicalTimeZone('Asia/Calcutta')).toBe('Asia/Kolkata')
    expect(canonicalTimeZone('Europe/London')).toBe('Europe/London')
    expect(canonicalTimeZone('Mars/Olympus')).toBeNull()
  })

  test('the offset is written as UTC±hh:mm', () => {
    const july = new Date('2026-07-01T00:00:00Z')
    expect(timeZoneOffset('Asia/Kathmandu', july)).toBe('UTC+05:45')
    expect(timeZoneOffset('UTC', july)).toBe('UTC+00:00')
    expect(timeZoneOffset('Europe/London', july)).toBe('UTC+01:00')
  })
})

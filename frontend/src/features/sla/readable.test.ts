import { describe, expect, test } from 'vitest'
import { dayLine, dayWindows, weeklyMinutes, workingTime } from './readable'

describe('SLA in words (M4-12)', () => {
  test('working time reads in minutes and hours, never days', () => {
    expect(workingTime(30)).toBe('30 min')
    expect(workingTime(240)).toBe('4 h')
    expect(workingTime(90)).toBe('1 h 30 min')
    expect(workingTime(1440)).toBe('24 h')
    expect(workingTime(0)).toBe('—')
  })

  test('a week adds up its windows and ignores malformed ones', () => {
    const week = {
      mon: [['10:00', '17:00']],
      tue: [
        ['09:00', '12:00'],
        ['13:00', '17:00'],
      ],
      wed: [['17:00', '09:00']],
    }
    expect(weeklyMinutes(week)).toBe(7 * 60 + 3 * 60 + 4 * 60)
    expect(dayWindows(week.wed)).toEqual([])
    expect(dayLine(week.tue)).toBe('09:00–12:00, 13:00–17:00')
    expect(dayLine(undefined)).toBe('Closed')
  })
})

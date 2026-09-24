import { describe, expect, test } from 'vitest'
import { dimensionLabel, timeDimension } from './dimension-labels'

const day = { key: 'day', label: 'Day', is_time: true }
const week = { key: 'week', label: 'Week', is_time: true }
const month = { key: 'month', label: 'Month', is_time: true }
const team = { key: 'team', label: 'Team', is_time: false }

describe('dimensionLabel (M4-09)', () => {
  test('days, weeks and months read in the app date format, never as ISO keys', () => {
    expect(dimensionLabel({ key: '2026-08-25', label: '2026-08-25' }, day)).toEqual({
      short: '25 Aug',
      long: 'Tue 25 Aug 2026',
    })
    expect(dimensionLabel({ key: '2026-08-24', label: '2026-08-24' }, week)).toEqual({
      short: 'Week of 24 Aug',
      long: 'Week of 24 Aug 2026',
    })
    expect(dimensionLabel({ key: '2026-09', label: '2026-09' }, month)).toEqual({
      short: 'Sep 2026',
      long: 'September 2026',
    })
  })

  test('a date key is a calendar date: no time zone moves it to the next or previous day', () => {
    expect(dimensionLabel({ key: '2026-01-01', label: '2026-01-01' }, day).short).toBe('1 Jan')
    expect(dimensionLabel({ key: '2026-12-31', label: '2026-12-31' }, day).short).toBe('31 Dec')
  })

  test('the "no value" row is named after its dimension', () => {
    expect(dimensionLabel({ key: '-', label: 'None' }, team)).toEqual({ short: 'No team', long: 'No team' })
  })

  test('other labels are the API labels, unchanged', () => {
    expect(dimensionLabel({ key: 'abc', label: 'Billing' }, team).short).toBe('Billing')
    expect(dimensionLabel({ key: '2026-08-25', label: 'x' }, undefined).short).toBe('x')
  })

  test('the dashboard knows only the group key', () => {
    expect(timeDimension('week', 'Week').is_time).toBe(true)
    expect(timeDimension('agent', 'Agent').is_time).toBe(false)
  })
})

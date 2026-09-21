import { describe, expect, test } from 'vitest'
import { describeChange, formatDuration, formatMeasure } from './format'

describe('formatMeasure', () => {
  test.each([
    [1234, 'count', '1,234'],
    [45, 'seconds', '45s'],
    [720, 'seconds', '12m'],
    [12_000, 'seconds', '3h 20m'],
    [3600, 'seconds', '1h'],
    [187_200, 'seconds', '2d 4h'],
    [92.46, 'percent', '92.5%'],
    [0.873, 'ratio', '0.87'],
    [2048, 'bytes', '2 KB'],
    [12.34, 'number', '12.3'],
    [null, 'count', '—'],
  ])('%s %s → %s', (value, unit, expected) => {
    expect(formatMeasure(value, unit)).toBe(expected)
  })

  test('negative durations keep their sign', () => {
    expect(formatDuration(-90)).toBe('−1m')
  })
})

describe('describeChange', () => {
  test('counts change in per cent of the previous value', () => {
    expect(describeChange(120, 100, 'count')).toEqual({
      direction: 'up',
      amount: '20%',
      text: 'Up 20% from 100',
    })
    expect(describeChange(50, 100, 'count')?.text).toBe('Down 50% from 100')
  })

  test('percentages change in points', () => {
    expect(describeChange(92.5, 90, 'percent')?.text).toBe('Up 2.5 pp from 90%')
  })

  test('from zero the difference itself is shown', () => {
    expect(describeChange(3, 0, 'count')?.text).toBe('Up 3 from 0')
  })

  test('no change, and no comparison without a previous value', () => {
    expect(describeChange(7, 7, 'count')?.direction).toBe('flat')
    expect(describeChange(7, null, 'count')).toBeNull()
    expect(describeChange(null, 7, 'count')).toBeNull()
  })
})

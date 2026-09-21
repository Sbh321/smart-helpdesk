import { describe, expect, test } from 'vitest'
import { chartKindFor, chartMeasuresFor } from './chart-choice'

describe('chartKindFor', () => {
  test.each([
    ['line', 'day', true, 'line'],
    ['line', 'team', false, 'bar'],
    ['stacked_area', 'day', true, 'area'],
    ['stacked_area', 'status', false, 'bar'],
    ['heatmap', 'weekday_hour', false, 'heatmap'],
    ['heatmap', 'weekday', false, 'bar'],
    ['bar', 'priority', false, 'bar'],
    ['bar', 'week', true, 'line'],
    ['histogram', 'age_bucket', false, 'histogram'],
    ['table', 'agent', false, 'table'],
  ])('%s grouped by %s → %s', (declared, group, isTime, expected) => {
    expect(chartKindFor(declared, group, isTime)).toBe(expected)
  })
})

describe('chartMeasuresFor', () => {
  const measures = [
    { key: 'tickets', label: 'Tickets', unit: 'count' },
    { key: 'median', label: 'Median', unit: 'seconds' },
    { key: 'resolved', label: 'Resolved', unit: 'count' },
  ]

  test('by default the first measure and the others of its unit: one value axis', () => {
    expect(chartMeasuresFor(measures, undefined).map((m) => m.key)).toEqual(['tickets', 'resolved'])
  })

  test('a chosen measure alone; an unknown choice falls back', () => {
    expect(chartMeasuresFor(measures, 'median').map((m) => m.key)).toEqual(['median'])
    expect(chartMeasuresFor(measures, 'nope').map((m) => m.key)).toEqual(['tickets', 'resolved'])
  })
})

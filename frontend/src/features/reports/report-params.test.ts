import { describe, expect, test } from 'vitest'
import { parseReportSearch, toRecordsQuery, toReportSearch, toRunBody } from './report-params'

const FILTERS = ['priority', 'team', 'age_bucket']

describe('report URL parameters', () => {
  test('the defaults are last 30 days, no comparison, the default group, no filters', () => {
    const params = parseReportSearch({}, FILTERS)
    expect(params).toMatchObject({ period: 'last_30d', range: undefined, compare: false, group: undefined })
    expect(toRunBody(params)).toEqual({ period: 'last_30d' })
    expect(toReportSearch(params, FILTERS)).toEqual({})
  })

  test('every parameter round-trips between the URL and the run request', () => {
    const search = { period: 'last_7d', compare: 'previous', group: 'team', priority: 'P1,P2', team: 'none' }
    const params = parseReportSearch(search, FILTERS)
    expect(toRunBody(params)).toEqual({
      period: 'last_7d',
      group: 'team',
      filter: { priority: 'P1,P2', team: 'none' },
      compare: true,
    })
    expect(toReportSearch(params, FILTERS)).toEqual(search)
  })

  test('a custom range replaces the period; a reversed or malformed one is ignored', () => {
    const params = parseReportSearch({ period: 'last_7d', from: '2026-09-01', to: '2026-09-10' }, FILTERS)
    expect(toRunBody(params)).toEqual({ from: '2026-09-01', to: '2026-09-10' })
    expect(parseReportSearch({ from: '2026-09-10', to: '2026-09-01' }, FILTERS).range).toBeUndefined()
    expect(parseReportSearch({ from: 'yesterday', to: '2026-09-01' }, FILTERS).range).toBeUndefined()
  })

  test('unknown periods fall back, unknown filters are dropped, numbers from the router become text', () => {
    const params = parseReportSearch({ period: 'forever', status: 'open', age_bucket: 1 }, FILTERS)
    expect(params.period).toBe('last_30d')
    expect(params.filters).toEqual({ age_bucket: ['1'] })
  })

  test('unrelated search keys pass through; drill-down state is kept', () => {
    const params = parseReportSearch({ drill: 'P1', drill_page: 2 }, FILTERS)
    expect(toReportSearch(params, FILTERS, { tab: 'x', priority: 'P4' })).toEqual({
      tab: 'x',
      drill: 'P1',
      drill_page: 2,
    })
  })

  test('the records query carries the run parameters and the row key; the totals have none', () => {
    const params = parseReportSearch({ group: 'priority', team: 'a' }, FILTERS)
    expect(toRecordsQuery(params, 'P1', 2)).toEqual({
      period: 'last_30d',
      group: 'priority',
      filter: { team: 'a' },
      key: 'P1',
      page: 2,
    })
    expect(toRecordsQuery(params, '*', 1)).not.toHaveProperty('key')
  })
})

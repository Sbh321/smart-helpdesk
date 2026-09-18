import { describe, expect, it } from 'vitest'
import { z } from 'zod'
import {
  applyListPatch,
  choiceFilter,
  countActiveFilters,
  dateRangeFilter,
  defineListSchema,
  formatSort,
  multiFilter,
  parseSort,
} from './list-schema'

const UUID_A = '019a0000-0000-7000-8000-000000000001'
const UUID_B = '019a0000-0000-7000-8000-000000000002'

const schema = defineListSchema({
  sortFields: ['name', 'email', 'created_at'],
  defaultSort: 'name',
  filters: {
    organization_id: multiFilter(z.uuid()),
    tag: multiFilter(),
    created_between: dateRangeFilter(),
  },
})

describe('defineListSchema — parsing the URL', () => {
  it('fills every default when the URL has no list params', () => {
    expect(schema.parse({})).toEqual({ page: 1, per_page: 25, sort: 'name', search: undefined, filters: {} })
    expect(schema.parse(undefined).page).toBe(1)
  })

  it('reads valid values, including numbers the router already JSON-parsed', () => {
    const params = schema.parse({
      page: 3,
      per_page: '50',
      sort: '-created_at',
      search: '  arjun  ',
      organization_id: `${UUID_A},${UUID_B}`,
      tag: 'vip',
      created_between: '2026-09-01,2026-09-17',
    })
    expect(params).toEqual({
      page: 3,
      per_page: 50,
      sort: '-created_at',
      search: 'arjun',
      filters: {
        organization_id: [UUID_A, UUID_B],
        tag: ['vip'],
        created_between: { from: '2026-09-01', to: '2026-09-17' },
      },
    })
  })

  it.each([
    ['page', { page: 0 }, 'page', 1],
    ['page', { page: -4 }, 'page', 1],
    ['page', { page: 'two' }, 'page', 1],
    ['page', { page: 2.5 }, 'page', 1],
    ['per_page', { per_page: 30 }, 'per_page', 25],
    ['per_page', { per_page: 1000 }, 'per_page', 25],
    ['per_page', { per_page: 'all' }, 'per_page', 25],
    ['search', { search: '   ' }, 'search', undefined],
    ['search', { search: 'x'.repeat(201) }, 'search', undefined],
  ] as const)('falls back to the default for an invalid %s', (_name, raw, key, expected) => {
    expect(schema.parse(raw)[key]).toBe(expected)
  })

  it('keeps a numeric search term as text', () => {
    expect(schema.parse({ search: 1042 }).search).toBe('1042')
  })

  it('rejects sort fields that are not on the allow-list and falls back to the default sort', () => {
    expect(schema.isSortAllowed('password')).toBe(false)
    expect(schema.isSortAllowed('-password')).toBe(false)
    expect(schema.isSortAllowed('name,email')).toBe(false)
    expect(schema.isSortAllowed('')).toBe(false)
    expect(schema.isSortAllowed(42)).toBe(false)
    expect(schema.isSortAllowed('-email')).toBe(true)
    expect(schema.parse({ sort: 'password' }).sort).toBe('name')
    expect(schema.parse({ sort: '--name' }).sort).toBe('name')
    expect(schema.searchSchema.parse({ sort: 'password' })).toEqual({})
  })

  it('drops invalid filter items but keeps the valid ones', () => {
    expect(schema.parse({ organization_id: `nope,${UUID_A},${UUID_A}` }).filters).toEqual({
      organization_id: [UUID_A],
    })
    expect(schema.parse({ organization_id: 'nope' }).filters).toEqual({})
    expect(schema.parse({ tag: ['vip', 'beta'] }).filters).toEqual({ tag: ['vip', 'beta'] })
    expect(schema.parse({ tag: ',,' }).filters).toEqual({})
  })

  it('rejects malformed or reversed date ranges', () => {
    for (const value of ['2026-09-01', '2026-09-17,2026-09-01', '2026-13-01,2026-13-02', 'a,b', '1,2,3']) {
      expect(schema.parse({ created_between: value }).filters).toEqual({})
    }
  })

  it('passes unrelated search params through validation', () => {
    expect(schema.searchSchema.parse({ contact: 'abc', page: 2 })).toEqual({ contact: 'abc', page: 2 })
  })

  it('refuses a filter named like a reserved list parameter, and a default sort off the allow-list', () => {
    expect(() =>
      defineListSchema({ sortFields: ['name'], defaultSort: 'name', filters: { page: multiFilter() } }),
    ).toThrow()
    expect(() =>
      defineListSchema({ sortFields: ['name'], defaultSort: 'email' as 'name', filters: {} }),
    ).toThrow()
  })
})

describe('defineListSchema — writing the URL and the API query', () => {
  it('leaves defaults out of the URL and keeps unrelated params', () => {
    const params = schema.parse({ tag: 'vip' })
    expect(schema.toSearch(params, { contact: 'abc', page: 4, tag: 'old' })).toEqual({
      contact: 'abc',
      tag: 'vip',
    })
  })

  it('writes non-default values as the API names them', () => {
    const params = schema.parse({
      page: 2,
      per_page: 100,
      sort: '-email',
      search: 'arj',
      organization_id: `${UUID_A},${UUID_B}`,
      created_between: '2026-09-01,2026-09-17',
    })
    expect(schema.toSearch(params)).toEqual({
      page: 2,
      per_page: 100,
      sort: '-email',
      search: 'arj',
      organization_id: `${UUID_A},${UUID_B}`,
      created_between: '2026-09-01,2026-09-17',
    })
    expect(schema.toApiQuery(params)).toEqual({
      page: 2,
      per_page: 100,
      sort: '-email',
      search: 'arj',
      'filter[organization_id]': `${UUID_A},${UUID_B}`,
      'filter[created_between]': '2026-09-01,2026-09-17',
    })
  })

  it('always sends the effective sort to the API', () => {
    expect(schema.toApiQuery(schema.parse({}))).toEqual({ page: 1, per_page: 25, sort: 'name' })
  })
})

describe('applyListPatch', () => {
  const base = schema.parse({ page: 4, sort: '-email', tag: 'vip' })

  it('keeps filters and sort when only the page changes', () => {
    expect(applyListPatch(base, { page: 5 }, 'name')).toEqual({ ...base, page: 5 })
  })

  it('resets the page to 1 when a filter, the search, the sort or the page size changes', () => {
    expect(applyListPatch(base, { filters: { tag: ['beta'] } }, 'name').page).toBe(1)
    expect(applyListPatch(base, { search: 'arj' }, 'name').page).toBe(1)
    expect(applyListPatch(base, { sort: 'name' }, 'name').page).toBe(1)
    expect(applyListPatch(base, { per_page: 50 }, 'name').page).toBe(1)
  })

  it('removes a filter set to undefined or to an empty list, and restores the default sort', () => {
    expect(applyListPatch(base, { filters: { tag: [] } }, 'name').filters).toEqual({})
    expect(applyListPatch(base, { filters: { tag: undefined } }, 'name').filters).toEqual({})
    expect(applyListPatch(base, { sort: undefined }, 'name').sort).toBe('name')
    expect(applyListPatch(base, { search: '   ' }, 'name').search).toBeUndefined()
  })

  it('counts the active filters, the search included', () => {
    expect(countActiveFilters(base)).toBe(1)
    expect(countActiveFilters(applyListPatch(base, { search: 'x' }, 'name'))).toBe(2)
  })
})

describe('sort helpers', () => {
  it('round-trips a sort value', () => {
    expect(parseSort('-created_at')).toEqual({ field: 'created_at', desc: true })
    expect(parseSort('name')).toEqual({ field: 'name', desc: false })
    expect(formatSort('name', true)).toBe('-name')
    expect(formatSort('name', false)).toBe('name')
  })
})

describe('two-field sorts and choice filters', () => {
  const tickets = defineListSchema({
    sortFields: ['priority_score', 'created_at', 'number'],
    defaultSort: '-priority_score,-created_at',
    filters: { archived: choiceFilter(['true', 'all']) },
  })

  it('accepts a two-field default and resolves to it', () => {
    expect(tickets.parse({}).sort).toBe('-priority_score,-created_at')
    expect(tickets.toApiQuery(tickets.parse({})).sort).toBe('-priority_score,-created_at')
    expect(tickets.toSearch(tickets.parse({}))).toEqual({})
  })

  it('rejects other two-field sorts unless the schema allows them', () => {
    expect(tickets.isSortAllowed('-created_at,number')).toBe(false)
    expect(tickets.parse({ sort: 'number,-created_at' }).sort).toBe('-priority_score,-created_at')
    const multi = defineListSchema({
      sortFields: ['a', 'b', 'c'],
      defaultSort: 'a',
      multiSort: true,
      filters: {},
    })
    expect(multi.isSortAllowed('-b,c')).toBe(true)
    expect(multi.isSortAllowed('a,a')).toBe(false)
    expect(multi.isSortAllowed('a,b,c')).toBe(false)
    expect(multi.isSortAllowed('a,x')).toBe(false)
  })

  it('reads the primary field of a two-field sort', () => {
    expect(parseSort('-priority_score,-created_at')).toEqual({ field: 'priority_score', desc: true })
  })

  it('keeps a choice filter only for one of its values', () => {
    expect(tickets.parse({ archived: 'all' }).filters).toEqual({ archived: 'all' })
    expect(tickets.parse({ archived: true }).filters).toEqual({ archived: 'true' })
    expect(tickets.parse({ archived: 'false' }).filters).toEqual({})
    expect(tickets.parse({ archived: 'maybe' }).filters).toEqual({})
    expect(tickets.toApiQuery(tickets.parse({ archived: 'all' }))).toMatchObject({
      'filter[archived]': 'all',
    })
    expect(tickets.toSearch(tickets.parse({ archived: 'true' }))).toEqual({ archived: 'true' })
  })
})

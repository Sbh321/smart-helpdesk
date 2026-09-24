import { describe, expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { attributeLabel, formatRecordValue, readableChanges, shortId } from './record-values'

const ZONE = 'Asia/Kathmandu'
const TEAM = '01a0c549-1f64-71de-933d-ef235eb3edd1'
const names = { team: (id: string) => (id === TEAM ? 'Customer Success' : undefined) }

describe('formatRecordValue', () => {
  test('names the record an id points at, and falls back to a short id', () => {
    expect(formatRecordValue(TEAM, ZONE, { attribute: 'team_id', names })).toBe('Customer Success')
    // An id of a record the viewer's caches do not cover: never the whole UUID.
    expect(
      formatRecordValue('01a0c549-27db-70b0-a631-4b00f3845866', ZONE, { attribute: 'team_id', names }),
    ).toBe('…f3845866')
    // An id in a column we know nothing about is still shortened.
    expect(formatRecordValue(TEAM, ZONE, { attribute: 'whatever' })).toBe('…5eb3edd1')
  })

  test('reads enumerations, instants, durations, booleans and nothing', () => {
    expect(formatRecordValue('in_progress', ZONE, { attribute: 'status' })).toBe(
      copy.reports.fixedLabels.status?.in_progress,
    )
    expect(formatRecordValue('P1', ZONE, { attribute: 'priority_level' })).toBe(
      copy.reports.fixedLabels.priority?.P1,
    )
    expect(formatRecordValue('2026-09-20T09:00:00Z', ZONE)).toBe('20 Sep 2026, 14:45:00')
    expect(formatRecordValue(3600, ZONE, { attribute: 'first_response_seconds' })).toBe('1h')
    expect(formatRecordValue(42, ZONE, { attribute: 'number' })).toBe('42')
    expect(formatRecordValue(true, ZONE)).toBe(copy.entity360.values.yes)
    for (const nothing of [null, undefined, '', [], {}]) {
      expect(formatRecordValue(nothing, ZONE)).toBe(copy.entity360.values.empty)
    }
  })

  test('a list or nested object either counts or spells out, by surface', () => {
    const value = { first_response_minutes: 10, resolution_minutes: 60 }
    expect(formatRecordValue(value, ZONE, { structured: 'count' })).toBe('2 values')
    expect(formatRecordValue(value, ZONE, { structured: 'join' })).toBe(
      'First response minutes: 10; Resolution minutes: 60',
    )
    expect(formatRecordValue(['agent', 'manager'], ZONE, { structured: 'join' })).toBe('agent, manager')
  })
})

describe('readableChanges', () => {
  test('turns a change map into labelled before and after values', () => {
    const lines = readableChanges(
      { team_id: { old: null, new: TEAM }, status: { old: 'open', new: 'assigned' } },
      ZONE,
      { names },
    )

    expect(lines).toEqual([
      { label: attributeLabel('team_id'), from: copy.entity360.values.empty, to: 'Customer Success' },
      {
        label: attributeLabel('status'),
        from: copy.reports.fixedLabels.status?.open,
        to: copy.reports.fixedLabels.status?.assigned,
      },
    ])
  })

  test('a value without a previous one has no "from" side', () => {
    const [line] = readableChanges({ impact: 2 }, ZONE)
    expect(line).toEqual({ label: attributeLabel('impact'), from: null, to: '2' })
  })

  test('only own keys are read, so a map cannot resolve through its prototype', () => {
    // The ticket timeline printed "at: function at() { [native code] }" before M4-03, because an empty
    // value map arrived as [] and was indexed by name.
    const lines = readableChanges(Object.assign(Object.create({ inherited: 'x' }), { at: 1 }), ZONE)
    expect(lines.map((line) => line.label)).toEqual([attributeLabel('at')])
  })
})

test('shortId keeps the last eight characters', () => {
  expect(shortId(TEAM)).toBe('…5eb3edd1')
})

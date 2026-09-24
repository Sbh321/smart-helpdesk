import { describe, expect, test } from 'vitest'
import { explainPriority } from './priority-explanation'

const stored = {
  strategy: 'basic_weighted_priority',
  strategy_version: '1.0.0',
  parts: [
    { name: 'impact', value: 0.3333, weight: 0.4, contribution: 13.3333 },
    { name: 'urgency', value: 0.6667, weight: 0.3, contribution: 20 },
    { name: 'tier', value: 0, weight: 0.2, contribution: 0 },
    { name: 'age', value: 0.5, weight: 0.1, contribution: 5 },
  ],
}

describe('explainPriority (M4-06)', () => {
  test('the shown contributions add up to the score and the shares to the whole bar', () => {
    const { score, factors } = explainPriority(stored)
    expect(score).toBeCloseTo(38.3333, 4)
    expect(factors.reduce((sum, factor) => sum + factor.contribution, 0)).toBeCloseTo(score, 10)
    expect(factors.reduce((sum, factor) => sum + factor.share, 0)).toBeCloseTo(1, 10)
  })

  test('factors are ordered by contribution and named from the copy file', () => {
    expect(explainPriority(stored).factors.map((factor) => factor.label)).toEqual([
      'Urgency',
      'Impact',
      'Age',
      'Organisation tier',
    ])
  })

  test('an unknown factor keeps its stored name and a zero score gives empty bars', () => {
    const { score, factors } = explainPriority({
      ...stored,
      parts: [{ name: 'backlog', value: 0, weight: 1, contribution: 0 }],
    })
    expect(score).toBe(0)
    expect(factors).toEqual([{ name: 'backlog', label: 'backlog', contribution: 0, share: 0 }])
  })
})

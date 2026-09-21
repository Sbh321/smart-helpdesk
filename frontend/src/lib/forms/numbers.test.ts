import { describe, expect, it } from 'vitest'
import { decimalText, integerText } from './numbers'

describe('number text schemas', () => {
  it('accepts whole numbers inside the range only', () => {
    const schema = integerText(1, 100, 'bad')
    expect(schema.safeParse('10').success).toBe(true)
    expect(schema.safeParse(' 100 ').success).toBe(true)
    for (const value of ['', '0', '101', '1.5', 'ten', '1e2']) {
      expect(schema.safeParse(value).success).toBe(false)
    }
  })

  it('accepts decimals inside the range only', () => {
    const schema = decimalText(0.1, 0.95, 'bad')
    expect(schema.safeParse('0.75').success).toBe(true)
    for (const value of ['', '0.05', '0.96', 'abc', '.5']) {
      expect(schema.safeParse(value).success).toBe(false)
    }
  })
})

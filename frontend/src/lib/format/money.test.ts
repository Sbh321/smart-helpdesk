import { describe, expect, test } from 'vitest'
import { formatMoney, fromMinor, toMinor } from './money'

describe('money', () => {
  test('formats minor units with the currency code', () => {
    expect(formatMoney(250000, 'NPR')).toBe('NPR 2,500.00')
    expect(formatMoney(5, 'USD')).toBe('USD 0.05')
  })

  test('reads what a person types, and refuses what is not an amount', () => {
    expect(toMinor('2,500')).toBe(250000)
    expect(toMinor('2500.5')).toBe(250050)
    expect(toMinor(' 10.05 ')).toBe(1005)
    expect(toMinor('10.005')).toBeNull()
    expect(toMinor('ten')).toBeNull()
    expect(fromMinor(250050)).toBe('2500.50')
  })
})

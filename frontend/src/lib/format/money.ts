/**
 * Amounts travel as integers in minor units (paisa, cents; ADR-0025): `formatMoney(250000, 'NPR')` is
 * "NPR 2,500.00". `toMinor` and `fromMinor` convert what a person types.
 */
export function formatMoney(minor: number, currency: string): string {
  try {
    return new Intl.NumberFormat('en', {
      style: 'currency',
      currency,
      currencyDisplay: 'code',
      minimumFractionDigits: 2,
    })
      .format(minor / 100)
      .replace(/ /g, ' ')
  } catch {
    return `${currency} ${(minor / 100).toFixed(2)}`
  }
}

/** "2,500.50" → 250050; null when it is not an amount. */
export function toMinor(text: string): number | null {
  const cleaned = text.replace(/[,\s]/g, '')
  if (!/^\d+(\.\d{1,2})?$/.test(cleaned)) return null
  return Math.round(Number(cleaned) * 100)
}

/** 250050 → "2500.50", for an input's value. */
export function fromMinor(minor: number): string {
  return (minor / 100).toFixed(2)
}

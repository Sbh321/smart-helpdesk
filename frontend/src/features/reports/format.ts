import { copy, fill } from '@/copy/en'
import { formatFileSize } from '@/lib/format/file-size'

/** Measure units the report API declares (`ReportDefinitionResource.measures[].unit`). */
export type MeasureUnit = 'count' | 'seconds' | 'percent' | 'ratio' | 'number' | 'bytes'

const units = copy.reports.units
const INTEGER = new Intl.NumberFormat('en', { maximumFractionDigits: 0 })
const ONE_DECIMAL = new Intl.NumberFormat('en', { maximumFractionDigits: 1 })
const TWO_DECIMALS = new Intl.NumberFormat('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** "45s", "12m", "3h 20m", "2d 4h": the two largest parts of a duration. */
export function formatDuration(totalSeconds: number): string {
  const seconds = Math.round(Math.abs(totalSeconds))
  const sign = totalSeconds < 0 ? '−' : ''
  if (seconds < 60) return `${sign}${seconds}${units.seconds}`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${sign}${minutes}${units.minutes}`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) {
    const rest = minutes % 60
    return `${sign}${hours}${units.hours}${rest > 0 ? ` ${rest}${units.minutes}` : ''}`
  }
  const days = Math.floor(hours / 24)
  const rest = hours % 24
  return `${sign}${days}${units.days}${rest > 0 ? ` ${rest}${units.hours}` : ''}`
}

/** A measure value as text, by its unit; `null` (no data, or a rate with no denominator) is a dash. */
export function formatMeasure(value: number | null | undefined, unit: string): string {
  if (value === null || value === undefined || Number.isNaN(value)) return units.empty
  switch (unit as MeasureUnit) {
    case 'count':
      return INTEGER.format(value)
    case 'seconds':
      return formatDuration(value)
    case 'percent':
      return `${ONE_DECIMAL.format(value)}${units.percent}`
    case 'ratio':
      return TWO_DECIMALS.format(value)
    case 'bytes':
      return formatFileSize(Math.round(value), units.bytes)
    default:
      return ONE_DECIMAL.format(value)
  }
}

export type ChangeDirection = 'up' | 'down' | 'flat'

export interface Change {
  direction: ChangeDirection
  /** "12%" or, for percentages, "2.5 pp" (percentage points). */
  amount: string
  /** The whole sentence for the tile: "Up 12% from 40". */
  text: string
}

/**
 * The change of a value against the previous period. Percentages compare in points; everything else in
 * per cent of the previous value (no per cent from 0: then the difference itself is shown).
 */
export function describeChange(value: number | null, previous: number | null, unit: string): Change | null {
  if (value === null || previous === null) return null
  const difference = value - previous
  const direction: ChangeDirection = Math.abs(difference) < 1e-9 ? 'flat' : difference > 0 ? 'up' : 'down'
  let amount: string
  if (unit === 'percent') {
    amount = `${ONE_DECIMAL.format(Math.abs(difference))} ${units.points}`
  } else if (previous === 0) {
    amount = formatMeasure(Math.abs(difference), unit)
  } else {
    amount = `${ONE_DECIMAL.format(Math.abs((difference / previous) * 100))}${units.percent}`
  }
  const previousText = formatMeasure(previous, unit)
  const text =
    direction === 'flat'
      ? fill(copy.reports.change.flat, { previous: previousText })
      : fill(direction === 'up' ? copy.reports.change.up : copy.reports.change.down, {
          change: amount,
          previous: previousText,
        })
  return { direction, amount, text }
}

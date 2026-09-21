import { TZDate } from '@date-fns/tz'
import { format, formatDistanceStrict } from 'date-fns'

/**
 * All timestamps arrive from the API as ISO-8601 UTC strings. They are shown in the tenant's time zone,
 * never the browser's implicit zone, so every agent sees the same SLA deadline.
 */
export function formatInZone(isoUtc: string, timeZone: string, pattern = 'd MMM yyyy, HH:mm'): string {
  return format(new TZDate(isoUtc, timeZone), pattern)
}

/** Human-readable distance between two instants, e.g. "2 hours". */
export function durationBetween(fromIsoUtc: string, toIsoUtc: string): string {
  return formatDistanceStrict(new Date(fromIsoUtc), new Date(toIsoUtc))
}

/**
 * A `datetime-local` input value ("2026-09-21T14:30") read as a wall time in `timeZone`, as an ISO UTC
 * instant; `null` when the value is not a complete date and time.
 */
export function zonedInputToIso(value: string, timeZone: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value)
  if (!match) return null
  const [, year, month, day, hour, minute, second] = match.map(Number)
  const date = new TZDate(
    year as number,
    (month as number) - 1,
    day as number,
    hour as number,
    minute as number,
    Number.isNaN(second) ? 0 : (second as number),
    timeZone,
  )
  return Number.isNaN(date.getTime()) ? null : new Date(date.getTime()).toISOString()
}

/** An ISO instant as a `datetime-local` value in `timeZone` (to the second). */
export function isoToZonedInput(isoUtc: string, timeZone: string): string {
  return format(new TZDate(isoUtc, timeZone), "yyyy-MM-dd'T'HH:mm:ss")
}

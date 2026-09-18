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

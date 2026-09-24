import { copy, fill } from '@/copy/en'
import { DAYS, type Day } from './weekly-hours'

const text = copy.sla.readable

/**
 * Minutes of working time in words (roadmap M4-12): "30 min", "4 h", "1 h 30 min", "24 h". Never days:
 * a target counts calendar working minutes, and a "day" would suggest 24 wall-clock hours.
 */
export function workingTime(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes <= 0) return text.none
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return fill(text.minutes, { minutes: rest })
  if (rest === 0) return fill(text.hours, { hours })
  return fill(text.hoursMinutes, { hours, minutes: rest })
}

function toMinutes(clock: string): number | null {
  const match = /^(\d{2}):(\d{2})$/.exec(clock)
  if (!match) return null
  return Number(match[1]) * 60 + Number(match[2])
}

/** One day's windows as minutes from midnight, dropping any pair that is not a valid `[start, end]`. */
export function dayWindows(
  pairs: readonly (readonly string[])[] | undefined,
): { start: number; end: number }[] {
  return (pairs ?? []).flatMap((pair) => {
    const start = toMinutes(pair[0] ?? '')
    const end = toMinutes(pair[1] ?? '')
    return start !== null && end !== null && end > start ? [{ start, end }] : []
  })
}

/** Working minutes in a week of the calendar. */
export function weeklyMinutes(
  hours: Record<string, readonly (readonly string[])[]> | null | undefined,
): number {
  return DAYS.reduce(
    (total, day) =>
      total + dayWindows(hours?.[day]).reduce((sum, window) => sum + window.end - window.start, 0),
    0,
  )
}

/** "09:00–17:00, 18:00–20:00" or "Closed" for a day. */
export function dayLine(pairs: readonly (readonly string[])[] | undefined): string {
  const windows = (pairs ?? []).filter((pair) => pair.length === 2)
  return windows.length === 0 ? copy.sla.closed : windows.map(([start, end]) => `${start}–${end}`).join(', ')
}

export type { Day }

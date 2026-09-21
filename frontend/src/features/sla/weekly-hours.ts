/**
 * The API's weekly hours are `{ mon: [["10:00", "17:00"], …], … }`. A form needs rows it can key and
 * edit, so the editor works on `WindowRow`s; these two functions are the only conversion, which keeps
 * the `[start, end]` tuple typed without a cast (a list response types the pairs as `string[][]`).
 */
export const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
export type Day = (typeof DAYS)[number]

export interface WindowRow {
  /** Client-side identity: a stable React key while rows are added and removed. */
  id: string
  start: string
  end: string
}

export type WeekRows = Record<Day, WindowRow[]>
export type WeeklyHours = Record<string, [string, string][]>

let sequence = 0

export function newWindow(start = '10:00', end = '17:00'): WindowRow {
  sequence += 1
  return { id: `window-${sequence}`, start, end }
}

export function emptyWeek(): WeekRows {
  return { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] }
}

/** Pairs that are not exactly `[start, end]` are dropped rather than guessed at. */
export function toWeekRows(
  hours: Record<string, readonly (readonly string[])[]> | null | undefined,
): WeekRows {
  const week = emptyWeek()
  for (const day of DAYS) {
    for (const pair of hours?.[day] ?? []) {
      const [start, end] = pair
      if (pair.length === 2 && start !== undefined && end !== undefined) week[day].push(newWindow(start, end))
    }
  }
  return week
}

/** Days without a window are left out: the API reads a missing day as closed. */
export function toWeeklyHours(week: WeekRows): WeeklyHours {
  const hours: WeeklyHours = {}
  for (const day of DAYS) {
    const pairs = week[day].map((row): [string, string] => [row.start, row.end])
    if (pairs.length > 0) hours[day] = pairs
  }
  return hours
}

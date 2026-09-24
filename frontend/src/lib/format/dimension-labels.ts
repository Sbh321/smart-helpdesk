import { format } from 'date-fns'
import { copy, fill } from '@/copy/en'

/** What a label formatter needs of a report dimension (`ReportDefinition['dimensions'][number]`). */
export interface DimensionInfo {
  key: string
  label: string
  is_time: boolean
}

/** A report row as the API returns it, plus the label a person should read. */
export interface LabelledRow<TRow extends { key: string; label: string }> {
  row: TRow
  /** Short, for an axis tick: "26 Aug", "Week of 24 Aug", "Sep 2026", "No team". */
  short: string
  /** Complete, for a tooltip, a table cell or a drill-down title: "Wed 26 Aug 2026". */
  long: string
}

/** The API's key for "no value" (DimensionLabels::NONE): a ticket without a team, an agent, … */
export const NO_VALUE_KEY = '-'

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/
const MONTH = /^(\d{4})-(\d{2})$/

/**
 * A calendar date of a report row as a local date. The API has already bucketed it in the workspace
 * zone, so it is a date, not an instant: no zone conversion may move it to the neighbouring day.
 */
function calendarDate(key: string): Date | null {
  const day = DAY.exec(key)
  if (day) return new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
  const month = MONTH.exec(key)
  if (month) return new Date(Number(month[1]), Number(month[2]) - 1, 1)
  return null
}

/**
 * The words for one row of a report dimension (roadmap M4-09, G4): time buckets in the app's date
 * format instead of ISO keys (`2026-08-25` → "25 Aug" on the axis, "Tue 25 Aug 2026" in the tooltip),
 * weeks named by their Monday, and the "no value" row named after its dimension ("No team") instead of
 * the API's generic "None". Any other label is the API's, unchanged.
 */
export function dimensionLabel(
  row: { key: string; label: string },
  dimension: DimensionInfo | undefined,
): { short: string; long: string } {
  if (row.key === NO_VALUE_KEY && dimension) {
    const none = fill(copy.reports.noValue, { dimension: dimension.label.toLowerCase() })
    return { short: none, long: none }
  }
  const date = dimension?.is_time ? calendarDate(row.key) : null
  if (!date || !dimension) return { short: row.label, long: row.label }
  if (MONTH.test(row.key)) {
    const month = format(date, 'MMM yyyy')
    return { short: month, long: format(date, 'MMMM yyyy') }
  }
  if (dimension.key === 'week') {
    return {
      short: fill(copy.reports.weekOf, { date: format(date, 'd MMM') }),
      long: fill(copy.reports.weekOf, { date: format(date, 'd MMM yyyy') }),
    }
  }
  return { short: format(date, 'd MMM'), long: format(date, 'EEE d MMM yyyy') }
}

/** `dimensionLabel` for every row, keeping the row. */
export function labelRows<TRow extends { key: string; label: string }>(
  rows: readonly TRow[],
  dimension: DimensionInfo | undefined,
): LabelledRow<TRow>[] {
  return rows.map((row) => ({ row, ...dimensionLabel(row, dimension) }))
}

/** The time dimensions of the catalogue, for callers that know only a group key (the dashboard). */
export function timeDimension(group: string, label: string): DimensionInfo {
  return { key: group, label, is_time: group === 'day' || group === 'week' || group === 'month' }
}

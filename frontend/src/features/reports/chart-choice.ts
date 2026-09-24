import type { ChartMeasure, SeriesKind } from '@/components/shared/charts/series-chart'

export type ChartKind = SeriesKind | 'heatmap' | 'table'

/**
 * The chart for a report's declared `chart` and the dimension in use. A run has one dimension, so:
 * line and stacked area are drawn over time only (grouped by anything else they become bars, a line
 * between categories would suggest an order that is not there); a stacked area is drawn as overlapping
 * areas, because its measures (end, average, highest backlog) are not parts of a whole; the heatmap
 * needs the weekday × hour grid and falls back to bars for any other grouping; a stacked bar stacks the
 * parts the report names in any grouping (upright over time, horizontal across categories).
 */
export function chartKindFor(declared: string, group: string, isTime: boolean): ChartKind {
  switch (declared) {
    case 'table':
      return 'table'
    case 'heatmap':
      return group === 'weekday_hour' ? 'heatmap' : isTime ? 'line' : 'bar'
    case 'histogram':
      return 'histogram'
    case 'line':
      return isTime ? 'line' : 'bar'
    case 'stacked_area':
      return isTime ? 'area' : 'bar'
    case 'stacked_bar':
      return 'stacked_bar'
    default:
      return isTime ? 'line' : 'bar'
  }
}

/**
 * The measures one chart shows: the chosen one; else the report's declared `chart_measures` (the parts
 * of a stacked bar); else the first measure and the others of its unit (one value axis, never two). At
 * most six (`--chart-1..6`).
 */
export function chartMeasuresFor(
  measures: readonly ChartMeasure[],
  chosen: string | undefined,
  declared: readonly string[] | null = null,
): ChartMeasure[] {
  const picked = chosen === undefined ? undefined : measures.find((measure) => measure.key === chosen)
  if (picked) return [picked]
  if (declared && declared.length > 0) {
    const parts = measures.filter((measure) => declared.includes(measure.key))
    if (parts.length > 0) return parts.slice(0, 6)
  }
  const first = measures[0]
  if (!first) return []
  return measures.filter((measure) => measure.unit === first.unit).slice(0, 6)
}

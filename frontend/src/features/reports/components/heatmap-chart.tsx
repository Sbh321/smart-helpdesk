import type { ChartMeasure, ChartRow } from '@/components/shared/charts/series-chart'
import { copy } from '@/copy/en'
import { formatMeasure } from '@/lib/format/measure'

export interface HeatmapChartProps {
  /** Rows keyed `<ISO weekday>-<hour>` (`1-09` is Monday 09:00), as `rpt-t11` groups by `weekday_hour`. */
  rows: readonly ChartRow[]
  measure: ChartMeasure
  label: string
}

const HOURS = Array.from({ length: 24 }, (_, hour) => String(hour).padStart(2, '0'))
const DAYS = ['1', '2', '3', '4', '5', '6', '7']

/**
 * Weekday × hour as a CSS grid. One hue, light to dark: each cell mixes `--chart-1` into the surface in
 * proportion to its value, so the scale follows the theme. The grid is one image to assistive
 * technology; the numbers are in the table beside it.
 */
export function HeatmapChart({ rows, measure, label }: HeatmapChartProps) {
  const values = new Map(rows.map((row) => [row.key, row.values[measure.key] ?? null]))
  const max = Math.max(0, ...rows.map((row) => row.values[measure.key] ?? 0))
  const shade = (value: number | null) => {
    if (value === null || value <= 0 || max <= 0) return 'var(--muted)'
    const share = Math.round(15 + (85 * value) / max)
    return `color-mix(in oklch, var(--chart-1) ${share}%, var(--surface))`
  }

  return (
    <figure className="flex flex-col gap-2">
      <div role="img" aria-label={label}>
        <div
          className="grid gap-0.5 text-[0.625rem] text-muted-foreground"
          style={{ gridTemplateColumns: '4.5rem repeat(24, minmax(0, 1fr))' }}
        >
          <span />
          {HOURS.map((hour) => (
            <span key={hour} className="text-center tabular-nums">
              {Number(hour) % 3 === 0 ? hour : ''}
            </span>
          ))}
          {DAYS.map((day, dayIndex) => (
            <div key={day} className="contents">
              <span className="truncate pr-1 text-xs">{copy.reports.weekdays[dayIndex]}</span>
              {HOURS.map((hour) => {
                const value = values.get(`${day}-${hour}`) ?? null
                return (
                  <span
                    key={hour}
                    data-value={value ?? 0}
                    title={`${copy.reports.weekdays[dayIndex]} ${hour}:00 — ${formatMeasure(value ?? 0, measure.unit)}`}
                    className="h-6 rounded-xs"
                    style={{ backgroundColor: shade(value) }}
                  />
                )
              })}
            </div>
          ))}
        </div>
      </div>
      <figcaption className="flex items-center justify-end gap-2 text-xs text-muted-foreground">
        <span>{copy.reports.lessMore.less}</span>
        {[0.1, 0.35, 0.6, 0.85, 1].map((step) => (
          <span
            key={step}
            aria-hidden="true"
            className="size-3 rounded-xs"
            style={{
              backgroundColor: `color-mix(in oklch, var(--chart-1) ${Math.round(15 + 85 * step)}%, var(--surface))`,
            }}
          />
        ))}
        <span>{copy.reports.lessMore.more}</span>
      </figcaption>
    </figure>
  )
}

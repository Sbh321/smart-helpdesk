import { Area, AreaChart, Bar, BarChart, CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts'
import {
  type ChartConfig,
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
} from '@/components/ui/chart'
import { formatMeasure } from '@/lib/format/measure'

export interface ChartMeasure {
  key: string
  label: string
  unit: string
}

export interface ChartRow {
  key: string
  /** The axis label, short ("25 Aug"). */
  label: string
  /** The tooltip label, complete ("Tue 25 Aug 2026"); the axis label when absent. */
  fullLabel?: string
  values: Record<string, number | null>
}

/**
 * How a series is drawn (docs/06-design-system/data-visualization.md):
 * - `line`, `area`: change over time;
 * - `bar`: comparing categories; horizontal (a ranking, long names fit), or upright columns over time;
 * - `stacked_bar`: parts of a whole per category or period; the report names the parts;
 * - `histogram`: a distribution over ordered buckets, upright and gapless.
 */
export type SeriesKind = 'line' | 'area' | 'bar' | 'stacked_bar' | 'histogram'

export interface SeriesChartProps {
  kind: SeriesKind
  rows: readonly ChartRow[]
  /** At most six, all of one unit: one value axis (docs/06-design-system/themes.md §Charts). */
  measures: readonly ChartMeasure[]
  /** Accessible name of the chart surface. */
  label: string
  /** The rows are periods (day, week, month): bars stand upright in time order. */
  time?: boolean
  className?: string
}

const MAX_SERIES = 6

/** `--chart-1..6` in series order; the variables follow the theme, so dark mode needs no re-render. */
export function chartConfig(measures: readonly ChartMeasure[]): ChartConfig {
  return Object.fromEntries(
    measures
      .slice(0, MAX_SERIES)
      .map((measure, index) => [measure.key, { label: measure.label, color: `var(--chart-${index + 1})` }]),
  )
}

/** Recharts reads flat objects: `{ label, <measure>: value }`. */
function chartData(rows: readonly ChartRow[], measures: readonly ChartMeasure[]) {
  return rows.map((row) => {
    const point: Record<string, string | number | null> = { key: row.key, label: row.label }
    for (const measure of measures) point[measure.key] = row.values[measure.key] ?? null
    return point
  })
}

/**
 * One chart of a report run or a dashboard series, over shadcn `ChartContainer` (Recharts 3). Colours
 * come from CSS variables, values in the axis and tooltip are formatted by unit, `accessibilityLayer`
 * lets the keyboard walk the points; the numbers are also in a table next to every chart.
 */
/** Axis tick numbers line up by digit (tabular figures). */
const TICKS = '[&_.recharts-cartesian-axis-tick_text]:tabular-nums'

export function SeriesChart({ kind, rows, measures, label, time = false, className }: SeriesChartProps) {
  const shown = measures.slice(0, MAX_SERIES)
  const config = chartConfig(shown)
  const data = chartData(rows, shown)
  const unit = shown[0]?.unit ?? 'count'
  const format = (value: unknown) => formatMeasure(typeof value === 'number' ? value : null, unit)
  // Always a legend, one series too: it is what names the measure the bars or line stand for (G6).
  // In series order (the stack's order), not Recharts' default alphabetical sort.
  const legend = <ChartLegend itemSorter={null} content={<ChartLegendContent />} />
  const fullLabels = new Map(rows.map((row) => [row.label, row.fullLabel ?? row.label]))
  const tooltip = (
    <ChartTooltip
      content={
        <ChartTooltipContent
          labelFormatter={(value) => fullLabels.get(String(value)) ?? String(value)}
          formatter={(value, name, item) => (
            <div className="flex w-full items-center justify-between gap-3">
              <span className="flex items-center gap-1.5 text-muted-foreground">
                <span
                  aria-hidden="true"
                  className="size-2.5 shrink-0 rounded-xs"
                  style={{ backgroundColor: item.color }}
                />
                {config[String(name)]?.label ?? name}
              </span>
              <span className="font-mono font-medium text-foreground tabular-nums">{format(value)}</span>
            </div>
          )}
        />
      }
    />
  )

  if (kind === 'line' || kind === 'area') {
    const Chart = kind === 'line' ? LineChart : AreaChart
    return (
      <ChartContainer config={config} className={className ?? `aspect-auto h-64 w-full ${TICKS}`}>
        <Chart accessibilityLayer title={label} data={data} margin={{ left: 4, right: 12, top: 8 }}>
          <CartesianGrid vertical={false} />
          <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={24} />
          <YAxis tickLine={false} axisLine={false} width={64} tickFormatter={format} />
          {tooltip}
          {legend}
          {shown.map((measure) =>
            kind === 'line' ? (
              <Line
                key={measure.key}
                dataKey={measure.key}
                type="monotone"
                stroke={`var(--color-${measure.key})`}
                strokeWidth={2}
                dot={data.length <= 14}
                isAnimationActive={false}
                connectNulls
              />
            ) : (
              <Area
                key={measure.key}
                dataKey={measure.key}
                type="monotone"
                stroke={`var(--color-${measure.key})`}
                fill={`var(--color-${measure.key})`}
                fillOpacity={0.2}
                strokeWidth={2}
                isAnimationActive={false}
                connectNulls
              />
            ),
          )}
        </Chart>
      </ChartContainer>
    )
  }

  // Bars: categories read best as horizontal bars (long names fit); periods and histograms stand upright.
  const horizontal = kind !== 'histogram' && !time
  const stacked = kind === 'stacked_bar'
  const height = horizontal ? Math.min(Math.max(rows.length * 32 + 72, 160), 640) : 256
  return (
    <ChartContainer config={config} className={className ?? `aspect-auto w-full ${TICKS}`} style={{ height }}>
      <BarChart
        accessibilityLayer
        title={label}
        data={data}
        layout={horizontal ? 'vertical' : 'horizontal'}
        margin={{ left: 4, right: 12, top: 8 }}
        barCategoryGap={kind === 'histogram' ? 1 : '20%'}
      >
        <CartesianGrid vertical={!horizontal} horizontal={!horizontal} />
        {horizontal ? (
          <>
            <XAxis type="number" tickLine={false} axisLine={false} tickFormatter={format} />
            <YAxis
              type="category"
              dataKey="label"
              tickLine={false}
              axisLine={false}
              width={128}
              interval={0}
            />
          </>
        ) : (
          <>
            <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={16} />
            <YAxis tickLine={false} axisLine={false} width={64} tickFormatter={format} />
          </>
        )}
        {tooltip}
        {legend}
        {shown.map((measure, index) => {
          // A stack rounds only its outer end; side-by-side bars round each one.
          const outer = !stacked || index === shown.length - 1
          const radius: [number, number, number, number] = !outer
            ? [0, 0, 0, 0]
            : horizontal
              ? [0, 4, 4, 0]
              : [4, 4, 0, 0]
          return (
            <Bar
              key={measure.key}
              dataKey={measure.key}
              fill={`var(--color-${measure.key})`}
              radius={radius}
              isAnimationActive={false}
              {...(stacked ? { stackId: 'parts' } : {})}
            />
          )
        })}
      </BarChart>
    </ChartContainer>
  )
}

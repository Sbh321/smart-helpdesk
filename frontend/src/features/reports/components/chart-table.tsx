import type { ChartMeasure, ChartRow } from '@/components/shared/charts/series-chart'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatMeasure } from '@/lib/format/measure'

export interface ChartTableProps {
  caption: string
  /** Header of the first column: the dimension ("Day", "Priority"). */
  dimensionLabel: string
  rows: readonly ChartRow[]
  measures: readonly ChartMeasure[]
}

/** The numbers of a chart as a plain table: the accessible alternative of every dashboard chart. */
export function ChartTable({ caption, dimensionLabel, rows, measures }: ChartTableProps) {
  return (
    <div className="overflow-hidden rounded-card border border-border bg-surface shadow-1">
      <table className="w-full text-sm">
        <caption className="sr-only">{caption}</caption>
        <TableHeader>
          <TableRow>
            <TableHead scope="col">{dimensionLabel}</TableHead>
            {measures.map((measure) => (
              <TableHead key={measure.key} scope="col" className="text-right">
                {measure.label}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.key}>
              <TableHead scope="row" className="font-normal">
                {row.label}
              </TableHead>
              {measures.map((measure) => (
                <TableCell key={measure.key} className="text-right tabular-nums">
                  {formatMeasure(row.values[measure.key] ?? null, measure.unit)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </table>
    </div>
  )
}

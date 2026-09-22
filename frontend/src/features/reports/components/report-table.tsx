import { useMemo, useState } from 'react'
import {
  DataTable,
  type DataTableState,
  type DataTableStatePatch,
  dataTableColumnHelper,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { DEFAULT_PAGE_SIZE, parseSort } from '@/lib/list-params'
import type { ReportRow } from '../api/report-queries'
import { formatMeasure } from '../format'
import type { ChartMeasure } from './series-chart'

const text = copy.reports
/** The API's row order (time ascending, or the report's ranking): no column shows as sorted. */
const NATURAL = 'natural'

export interface ReportTableProps {
  id: string
  title: string
  dimensionLabel: string
  rows: ReportRow[] | undefined
  measures: readonly ChartMeasure[]
  isFetching: boolean
  error: unknown
  onRetry: () => void
  /** Present when the report drills down: opens the records one count measure counts in a row. */
  onDrill?: ((row: ReportRow, measure: string) => void) | undefined
}

function compareRows(a: ReportRow, b: ReportRow, field: string): number {
  if (field === 'label') return a.label.localeCompare(b.label)
  const left = a.values[field] ?? Number.NEGATIVE_INFINITY
  const right = b.values[field] ?? Number.NEGATIVE_INFINITY
  return left === right ? 0 : left < right ? -1 : 1
}

/**
 * The run's rows in the shared `DataTable`, paged and sorted in the browser (a run returns every row at
 * once): the same numbers as the chart, formatted by unit. With drill-down, each count is a button that
 * opens the records behind the row.
 */
export function ReportTable({
  id,
  title,
  dimensionLabel,
  rows,
  measures,
  isFetching,
  error,
  onRetry,
  onDrill,
}: ReportTableProps) {
  const [state, setState] = useState<DataTableState>({ page: 1, per_page: DEFAULT_PAGE_SIZE, sort: NATURAL })

  const columns = useMemo(() => {
    const helper = dataTableColumnHelper<ReportRow>()
    return helper.columns([
      helper.accessor('label', {
        id: 'label',
        header: dimensionLabel,
        meta: { label: dimensionLabel },
        enableSorting: true,
        enableHiding: false,
      }),
      ...measures.map((measure) =>
        helper.display({
          id: measure.key,
          header: measure.label,
          meta: { label: measure.label, className: 'text-right' },
          enableSorting: true,
          cell: ({ row }) => {
            const value = row.original.values[measure.key] ?? null
            const shown = formatMeasure(value, measure.unit)
            if (!onDrill || measure.unit !== 'count' || value === null || value === 0) {
              return <span className="tabular-nums">{shown}</span>
            }
            return (
              <Button
                type="button"
                variant="link"
                size="sm"
                className="h-auto p-0 tabular-nums"
                aria-label={fill(text.viewRecords, {
                  value: shown,
                  label: row.original.label,
                  measure: measure.label,
                })}
                onClick={() => onDrill(row.original, measure.key)}
              >
                {shown}
              </Button>
            )
          },
        }),
      ),
    ])
  }, [dimensionLabel, measures, onDrill])

  const sorted = useMemo(() => {
    if (!rows) return undefined
    const { field, desc } = parseSort(state.sort)
    if (field === NATURAL) return rows
    const copyOfRows = [...rows].sort((a, b) => compareRows(a, b, field))
    return desc ? copyOfRows.reverse() : copyOfRows
  }, [rows, state.sort])
  const page = sorted?.slice((state.page - 1) * state.per_page, state.page * state.per_page)

  const update = (patch: DataTableStatePatch) =>
    setState((current) => ({
      page: patch.page ?? (patch.per_page !== undefined || 'sort' in patch ? 1 : current.page),
      per_page: patch.per_page ?? current.per_page,
      sort: 'sort' in patch ? (patch.sort ?? NATURAL) : current.sort,
    }))

  return (
    <DataTable
      id={`report-${id}`}
      label={fill(text.tableLabel, { title })}
      columns={columns}
      data={page}
      rowCount={sorted?.length}
      state={state}
      onStateChange={update}
      defaultSort={NATURAL}
      getRowId={(row) => row.key}
      isFetching={isFetching}
      error={error}
      onRetry={onRetry}
      emptyState={<EmptyState title={text.noRows} description={text.noRowsBody} />}
    />
  )
}

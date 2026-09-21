import { useState } from 'react'
import { DataTable, type DataTableColumns, type DataTableState } from '@/components/shared/data-table'
import { copy } from '@/copy/en'
import { DEFAULT_PAGE_SIZE } from '@/lib/list-params'

const NATURAL = 'natural'

export interface LocalTableProps<TRow extends Record<string, unknown>> {
  id: string
  label: string
  columns: DataTableColumns<TRow>
  rows: readonly TRow[]
  getRowId: (row: TRow) => string
  emptyText?: string
}

/**
 * The shared `DataTable` over rows already in hand (an overview's related records): paged in the
 * browser, in the API's order, no sorting.
 */
export function LocalTable<TRow extends Record<string, unknown>>({
  id,
  label,
  columns,
  rows,
  getRowId,
  emptyText = copy.entity360.empty,
}: LocalTableProps<TRow>) {
  const [state, setState] = useState<DataTableState>({ page: 1, per_page: DEFAULT_PAGE_SIZE, sort: NATURAL })
  const page = rows.slice((state.page - 1) * state.per_page, state.page * state.per_page)
  return (
    <DataTable
      id={id}
      label={label}
      columns={columns}
      data={page}
      rowCount={rows.length}
      state={state}
      onStateChange={(patch) =>
        setState((current) => ({
          page: patch.page ?? (patch.per_page !== undefined ? 1 : current.page),
          per_page: patch.per_page ?? current.per_page,
          sort: NATURAL,
        }))
      }
      defaultSort={NATURAL}
      getRowId={getRowId}
      emptyState={<p className="text-sm text-muted-foreground">{emptyText}</p>}
    />
  )
}

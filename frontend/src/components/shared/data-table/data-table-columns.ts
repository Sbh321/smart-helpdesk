import {
  type ColumnHelper,
  columnVisibilityFeature,
  createColumnHelper,
  metaHelper,
  type RowData,
  rowPaginationFeature,
  rowSelectionFeature,
  rowSortingFeature,
  type TableOptions,
  tableFeatures,
} from '@tanstack/react-table'

/** Per-column presentation that the table itself does not know about. */
export interface DataTableColumnMeta {
  /** Name in the column menu and the accessible name of the sort button; the header shows it too. */
  label: string
  /** Extra classes for the header and body cells of this column (width, alignment). */
  className?: string
}

/**
 * The TanStack Table v9 features every `DataTable` registers. Server mode: no client row models, the
 * rows arrive sorted and paginated from the API (docs/03-architecture/frontend.md §Tables). Filtering is
 * not a table feature here — filters are not per-column, they live in the URL next to sort and page.
 */
export const dataTableFeatures = tableFeatures({
  rowSortingFeature,
  rowPaginationFeature,
  rowSelectionFeature,
  columnVisibilityFeature,
  columnMeta: metaHelper<DataTableColumnMeta>(),
})

export type DataTableFeatures = typeof dataTableFeatures

/** Column definitions for a `DataTable`, as `helper.columns([...])` returns them. */
export type DataTableColumns<TRow extends RowData> = TableOptions<DataTableFeatures, TRow>['columns']

/**
 * The column helper bound to the DataTable feature set. Sorting is opt-in per column
 * (`enableSorting: true`) and the column id must be a field on the endpoint's sort allow-list.
 */
export function dataTableColumnHelper<TRow extends RowData>(): ColumnHelper<DataTableFeatures, TRow> {
  return createColumnHelper<DataTableFeatures, TRow>()
}

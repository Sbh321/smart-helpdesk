import {
  type ColumnVisibilityState,
  flexRender,
  type RowData,
  type RowSelectionState,
  type SortingState,
  type Updater,
  useTable,
} from '@tanstack/react-table'
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon, SearchXIcon } from 'lucide-react'
import { type MouseEvent, type ReactNode, useId, useMemo, useState } from 'react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { formatSort, type PageSize, parseSort, type SortSpec, type SortValue } from '@/lib/list-params'
import { cn } from '@/lib/utils'
import {
  type ColumnVisibility,
  readColumnVisibility,
  writeColumnVisibility,
} from './column-visibility-storage'
import { DataTableColumnToggle } from './data-table-column-toggle'
import { type DataTableColumns, dataTableFeatures } from './data-table-columns'
import { DataTablePagination } from './data-table-pagination'
import { useRowNavigation } from './use-row-navigation'

/** The server-owned state of a list, with the API's parameter names (it is the URL state). */
export interface DataTableState<TSort extends string = string> {
  page: number
  per_page: PageSize
  sort: SortSpec<TSort>
}

/** A change the table asks for; `sort: undefined` means "back to the default sort". */
export type DataTableStatePatch<TSort extends string = string> = {
  page?: number
  per_page?: PageSize
  sort?: SortSpec<TSort> | undefined
}

export interface DataTableSelection {
  /**
   * Ids of the selected rows: on the loaded page, or every selected id (across pages) when the parent
   * controls the selection through `selection`.
   */
  ids: string[]
  clear: () => void
}

/** A selection the parent owns; ids stay selected when the page, sort or filters change. */
export interface DataTableControlledSelection {
  ids: readonly string[]
  onChange: (ids: string[]) => void
}

export interface DataTableProps<TRow extends RowData, TSort extends string = string> {
  /** Stable table id; column visibility is remembered per id (`sh.table.<id>.columns`). */
  id: string
  /** Accessible name of the table (its caption). */
  label: string
  columns: DataTableColumns<TRow>
  /** `undefined` until the first page arrived. */
  data: TRow[] | undefined
  /** The API's `meta.total`. */
  rowCount: number | undefined
  state: DataTableState<TSort>
  onStateChange: (patch: DataTableStatePatch<TSort>) => void
  /** The endpoint's default sort; lets the sort cycle skip a step that would look like no change. */
  defaultSort?: NoInfer<SortSpec<TSort>>
  getRowId: (row: TRow) => string
  /** Names a row for its selection checkbox ("Select Arjun Rai"). */
  getRowLabel?: (row: TRow) => string
  /** Enter on a focused row, or a click on it. */
  onRowOpen?: (row: TRow) => void
  /** True while a page is being fetched; the previous page stays visible. */
  isFetching?: boolean
  /** The query error, if the last fetch failed. */
  error?: unknown
  onRetry?: () => void
  /** Shown when the page is empty; usually differs with and without active filters. */
  emptyState: ReactNode
  /** Left side of the toolbar, usually a `FilterBar`. */
  toolbar?: ReactNode
  /** Renders the bulk actions; the selection column exists only when this is given. */
  bulkActions?: (selection: DataTableSelection) => ReactNode
  /** Makes the selection controlled (kept across pages); without it the selection is local. */
  selection?: DataTableControlledSelection
  /** Visibility of columns the viewer has not toggled yet (`{ sla_due_at: false }` hides one by default). */
  defaultColumnVisibility?: ColumnVisibility
}

const EMPTY_ROWS: never[] = []
const SKELETON_ROWS = 8

function isInteractive(target: EventTarget | null, row: HTMLElement): boolean {
  const element =
    target instanceof Element ? target.closest('a, button, input, label, [role="checkbox"]') : null
  return element !== null && row.contains(element) && element !== row
}

/**
 * The list table of every screen (docs/06-design-system/components.md §DataTable), on TanStack Table v9
 * in server mode: the parent passes one page of rows, the total and the URL state; sort and page changes
 * go back out through `onStateChange` and come in again through the URL. Selection and column visibility
 * are the only local state.
 */
export function DataTable<TRow extends RowData, TSort extends string = string>({
  id,
  label,
  columns,
  data,
  rowCount,
  state,
  onStateChange,
  defaultSort,
  getRowId,
  getRowLabel,
  onRowOpen,
  isFetching = false,
  error,
  onRetry,
  emptyState,
  toolbar,
  bulkActions,
  selection,
  defaultColumnVisibility,
}: DataTableProps<TRow, TSort>) {
  // TanStack Table's row and header methods read table state the React Compiler cannot see, so a
  // memoised row would keep showing stale selection or visibility. This component opts out.
  'use no memo'

  const hintId = useId()
  const [localSelection, setLocalSelection] = useState<RowSelectionState>({})
  const controlledIds = selection?.ids
  const controlledSelection = useMemo<RowSelectionState | undefined>(
    () => (controlledIds ? Object.fromEntries(controlledIds.map((rowId) => [rowId, true])) : undefined),
    [controlledIds],
  )
  const rowSelection = controlledSelection ?? localSelection
  const setRowSelection = (next: RowSelectionState) => {
    if (selection) {
      selection.onChange(Object.keys(next).filter((rowId) => next[rowId] === true))
    } else {
      setLocalSelection(next)
    }
  }
  const [columnVisibility, setColumnVisibility] = useState<ColumnVisibilityState>(() => ({
    ...defaultColumnVisibility,
    ...readColumnVisibility(id),
  }))
  const current = parseSort(state.sort)
  // Controlled slices must keep their identity between renders: useTable publishes a changed slice
  // back into its store, which re-renders, which would build a new array again — a render loop.
  const sorting = useMemo<SortingState>(
    () => [{ id: current.field, desc: current.desc }],
    [current.field, current.desc],
  )
  const pagination = useMemo(
    () => ({ pageIndex: state.page - 1, pageSize: state.per_page }),
    [state.page, state.per_page],
  )

  const table = useTable({
    features: dataTableFeatures,
    columns,
    data: data ?? EMPTY_ROWS,
    getRowId: (row) => getRowId(row),
    rowCount: rowCount ?? 0,
    manualPagination: true,
    manualSorting: true,
    enableMultiSort: false,
    defaultColumn: { enableSorting: false },
    state: {
      sorting,
      pagination,
      rowSelection,
      columnVisibility,
    },
    onRowSelectionChange: (updater: Updater<RowSelectionState>) =>
      setRowSelection(typeof updater === 'function' ? updater(rowSelection) : updater),
    onColumnVisibilityChange: (updater: Updater<ColumnVisibilityState>) => {
      const next = typeof updater === 'function' ? updater(columnVisibility) : updater
      setColumnVisibility(next)
      writeColumnVisibility(id, next)
    },
  })

  const rows = table.getRowModel().rows
  const selectable = bulkActions !== undefined
  const pageIds = rows.map((row) => row.id)
  const selectedOnPage = pageIds.filter((rowId) => rowSelection[rowId] === true)
  const allSelected = pageIds.length > 0 && selectedOnPage.length === pageIds.length
  const selectedIds = controlledIds ? [...controlledIds] : selectedOnPage
  const clearSelection = () => setRowSelection({})

  const openRow = (index: number) => {
    const row = rows[index]
    if (row && onRowOpen) onRowOpen(row.original)
  }
  const toggleRow = (index: number) => {
    const row = rows[index]
    if (row) row.toggleSelected(rowSelection[row.id] !== true)
  }
  const navigation = useRowNavigation({
    rowCount: rows.length,
    ...(onRowOpen ? { onOpen: openRow } : {}),
    ...(selectable ? { onToggle: toggleRow } : {}),
  })

  const sortBy = (field: string) => {
    // asc → desc → back to the endpoint's default order. When the default already sorts by this field in
    // the direction just left (`-priority_score,-created_at` after `-priority_score`), the "back to
    // default" step would change nothing visible, so the other direction is taken instead.
    let next: SortSpec<TSort> | undefined = formatSort(field as TSort, false)
    if (current.field === field) {
      next = current.desc ? undefined : formatSort(field as TSort, true)
    }
    const landing = parseSort(next ?? defaultSort ?? '')
    if (next === undefined && landing.field === field && landing.desc === current.desc) {
      next = formatSort(field as TSort, !current.desc) as SortValue<TSort>
    }
    onStateChange({ sort: next })
  }

  const leafColumns = table.getVisibleLeafColumns()
  const columnCount = leafColumns.length + (selectable ? 1 : 0)
  const toggleItems = table
    .getAllLeafColumns()
    .filter((column) => column.getCanHide())
    .map((column) => ({
      id: column.id,
      label: column.columnDef.meta?.label ?? column.id,
      visible: columnVisibility[column.id] !== false,
    }))

  const isLoading = data === undefined && !error
  const isEmpty = data !== undefined && rows.length === 0

  let body: ReactNode
  if (error && data === undefined) {
    body = <ErrorState error={error} {...(onRetry ? { onRetry } : {})} />
  } else if (isEmpty && state.page > 1) {
    body = (
      <EmptyState
        icon={SearchXIcon}
        title={copy.dataTable.pageOutOfRange.title}
        description={copy.dataTable.pageOutOfRange.body}
        action={
          <Button type="button" variant="outline" onClick={() => onStateChange({ page: 1 })}>
            {copy.dataTable.pageOutOfRange.action}
          </Button>
        }
      />
    )
  } else if (isEmpty) {
    body = emptyState
  } else {
    body = (
      <div
        data-slot="data-table-scroll"
        className="relative max-h-[min(70dvh,48rem)] overflow-auto rounded-lg border border-border"
      >
        <table
          className={cn('w-full caption-bottom text-sm', isFetching && 'opacity-70 transition-opacity')}
          aria-busy={isLoading || isFetching}
          aria-describedby={onRowOpen ? hintId : undefined}
          aria-rowcount={rowCount !== undefined ? rowCount + 1 : undefined}
        >
          <caption className="sr-only">{label}</caption>
          <TableHeader className="sticky top-0 z-10 bg-surface shadow-[inset_0_-1px_0_var(--border)]">
            <TableRow aria-rowindex={1} className="hover:bg-transparent">
              {selectable ? (
                <TableHead className="w-10 px-3">
                  <Checkbox
                    aria-label={copy.dataTable.selectAll}
                    checked={allSelected}
                    indeterminate={selectedOnPage.length > 0 && !allSelected}
                    disabled={rows.length === 0}
                    onCheckedChange={(checked) => table.toggleAllPageRowsSelected(checked)}
                  />
                </TableHead>
              ) : null}
              {leafColumns.map((column) => {
                const meta = column.columnDef.meta
                const text = meta?.label ?? column.id
                if (!column.getCanSort()) {
                  return (
                    <TableHead key={column.id} scope="col" className={meta?.className}>
                      {text}
                    </TableHead>
                  )
                }
                const direction = current.field === column.id ? (current.desc ? 'desc' : 'asc') : undefined
                const Icon =
                  direction === 'asc' ? ArrowUpIcon : direction === 'desc' ? ArrowDownIcon : ArrowUpDownIcon
                return (
                  <TableHead
                    key={column.id}
                    scope="col"
                    aria-sort={
                      direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'
                    }
                    className={cn('px-1', meta?.className)}
                  >
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className={cn('-my-1 font-medium', direction === undefined && 'text-muted-foreground')}
                      onClick={() => sortBy(column.id)}
                    >
                      {text}
                      <Icon aria-hidden="true" />
                    </Button>
                  </TableHead>
                )
              })}
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading
              ? Array.from({ length: Math.min(state.per_page, SKELETON_ROWS) }, (_, index) => (
                  // biome-ignore lint/suspicious/noArrayIndexKey: placeholder rows have no identity
                  <TableRow key={index} aria-hidden="true" className="hover:bg-transparent">
                    {Array.from({ length: columnCount }, (_, cell) => (
                      // biome-ignore lint/suspicious/noArrayIndexKey: placeholder cells have no identity
                      <TableCell key={cell} className="h-10">
                        <Skeleton className="h-4 w-full max-w-40" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              : rows.map((row, index) => {
                  const selected = rowSelection[row.id] === true
                  const rowProps = navigation.getRowProps(index)
                  return (
                    <TableRow
                      key={row.id}
                      {...rowProps}
                      aria-rowindex={(state.page - 1) * state.per_page + index + 2}
                      data-state={selected ? 'selected' : undefined}
                      className={cn(
                        'h-10 outline-none focus-visible:bg-muted/60 focus-visible:shadow-[inset_2px_0_0_var(--ring)]',
                        onRowOpen && 'cursor-pointer',
                      )}
                      onClick={
                        onRowOpen
                          ? (event: MouseEvent<HTMLTableRowElement>) => {
                              if (!isInteractive(event.target, event.currentTarget)) onRowOpen(row.original)
                            }
                          : undefined
                      }
                    >
                      {selectable ? (
                        <TableCell className="w-10 px-3">
                          <Checkbox
                            aria-label={fill(copy.dataTable.selectRow, {
                              label: getRowLabel ? getRowLabel(row.original) : row.id,
                            })}
                            checked={selected}
                            onCheckedChange={(checked) => row.toggleSelected(checked)}
                          />
                        </TableCell>
                      ) : null}
                      {row.getVisibleCells().map((cell) => (
                        <TableCell key={cell.id} className={cell.column.columnDef.meta?.className}>
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </TableCell>
                      ))}
                    </TableRow>
                  )
                })}
          </TableBody>
        </table>
        {isLoading ? (
          <p role="status" className="sr-only">
            {copy.dataTable.loading}
          </p>
        ) : null}
        {onRowOpen ? (
          <p id={hintId} className="sr-only">
            {copy.dataTable.keyboardHint}
          </p>
        ) : null}
      </div>
    )
  }

  return (
    <div data-slot="data-table" className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">{toolbar}</div>
        <DataTableColumnToggle
          columns={toggleItems}
          onToggle={(columnId, visible) => table.getColumn(columnId)?.toggleVisibility(visible)}
        />
      </div>
      {selectable && selectedIds.length > 0 ? (
        <section
          aria-label={copy.dataTable.bulkActions}
          className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
        >
          <p className="font-medium tabular-nums" aria-live="polite">
            {fill(copy.dataTable.selectedCount, { count: selectedIds.length })}
          </p>
          <div className="flex flex-1 flex-wrap items-center gap-2">
            {bulkActions({ ids: selectedIds, clear: clearSelection })}
          </div>
          <Button type="button" variant="ghost" size="sm" onClick={clearSelection}>
            {copy.dataTable.clearSelection}
          </Button>
        </section>
      ) : null}
      {body}
      {rowCount !== undefined && rowCount > 0 ? (
        <DataTablePagination
          page={state.page}
          perPage={state.per_page}
          rowCount={rowCount}
          onPageChange={(page) => onStateChange({ page })}
          onPerPageChange={(perPage) => onStateChange({ per_page: perPage })}
        />
      ) : null}
    </div>
  )
}

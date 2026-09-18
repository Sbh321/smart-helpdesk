import { ChevronLeftIcon, ChevronRightIcon, ChevronsLeftIcon, ChevronsRightIcon } from 'lucide-react'
import { useId } from 'react'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { copy, fill } from '@/copy/en'
import { PAGE_SIZES, type PageSize } from '@/lib/list-params'

export interface DataTablePaginationProps {
  page: number
  perPage: PageSize
  rowCount: number
  onPageChange: (page: number) => void
  onPerPageChange: (perPage: PageSize) => void
}

const numberFormat = new Intl.NumberFormat('en')
const PAGE_SIZE_ITEMS = PAGE_SIZES.map((size) => ({ value: size, label: String(size) }))

/** The table footer: page size, the visible range and page navigation. */
export function DataTablePagination({
  page,
  perPage,
  rowCount,
  onPageChange,
  onPerPageChange,
}: DataTablePaginationProps) {
  const labelId = useId()
  const pageCount = Math.max(1, Math.ceil(rowCount / perPage))
  const from = rowCount === 0 ? 0 : Math.min((page - 1) * perPage + 1, rowCount)
  const to = Math.min(page * perPage, rowCount)
  const canGoBack = page > 1
  const canGoForward = page < pageCount

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
      <div className="flex items-center gap-2">
        <span id={labelId} className="text-muted-foreground">
          {copy.dataTable.rowsPerPage}
        </span>
        <Select
          items={PAGE_SIZE_ITEMS}
          value={perPage}
          onValueChange={(value) => {
            if (typeof value === 'number' && value !== perPage) {
              onPerPageChange(value as PageSize)
            }
          }}
        >
          <SelectTrigger size="sm" aria-labelledby={labelId} className="w-20">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PAGE_SIZE_ITEMS.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <nav aria-label={copy.dataTable.pagination} className="flex items-center gap-3">
        <p className="text-muted-foreground tabular-nums" aria-live="polite">
          {fill(copy.dataTable.range, {
            from: numberFormat.format(from),
            to: numberFormat.format(to),
            total: numberFormat.format(rowCount),
          })}
        </p>
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label={copy.dataTable.firstPage}
            disabled={!canGoBack}
            onClick={() => onPageChange(1)}
          >
            <ChevronsLeftIcon aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label={copy.dataTable.previousPage}
            disabled={!canGoBack}
            onClick={() => onPageChange(page - 1)}
          >
            <ChevronLeftIcon aria-hidden="true" />
          </Button>
          <span className="px-1 text-muted-foreground tabular-nums">
            {fill(copy.dataTable.pageOf, { page, pages: pageCount })}
          </span>
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label={copy.dataTable.nextPage}
            disabled={!canGoForward}
            onClick={() => onPageChange(page + 1)}
          >
            <ChevronRightIcon aria-hidden="true" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label={copy.dataTable.lastPage}
            disabled={!canGoForward}
            onClick={() => onPageChange(pageCount)}
          >
            <ChevronsRightIcon aria-hidden="true" />
          </Button>
        </div>
      </nav>
    </div>
  )
}

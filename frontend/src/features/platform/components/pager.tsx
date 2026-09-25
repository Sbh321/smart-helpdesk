import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'

/** Previous and next for a paginated console list, with where we are. Nothing when there is one page. */
export function Pager({
  page,
  lastPage,
  onPage,
}: {
  page: number
  lastPage: number
  onPage: (page: number) => void
}) {
  if (lastPage <= 1) return null
  return (
    <nav aria-label={copy.dataTable.pagination} className="flex items-center justify-end gap-2 text-sm">
      <span className="text-muted-foreground tabular-nums">
        {fill(copy.dataTable.pageOf, { page, pages: lastPage })}
      </span>
      <Button
        type="button"
        variant="outline"
        size="icon-sm"
        aria-label={copy.dataTable.previousPage}
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
      >
        <ChevronLeftIcon aria-hidden="true" />
      </Button>
      <Button
        type="button"
        variant="outline"
        size="icon-sm"
        aria-label={copy.dataTable.nextPage}
        disabled={page >= lastPage}
        onClick={() => onPage(page + 1)}
      >
        <ChevronRightIcon aria-hidden="true" />
      </Button>
    </nav>
  )
}

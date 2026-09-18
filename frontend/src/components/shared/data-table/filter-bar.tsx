import { XIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

export interface FilterBarProps {
  /** The filter controls: `SearchFilter`, `MultiSelectFilter`, `DateRangeFilter`. */
  children: ReactNode
  /** Filters plus search in effect; "Clear filters" shows when it is above zero. */
  activeCount: number
  onClear: () => void
  className?: string
}

/** The row of filters above a `DataTable`; its state is the route's search params (`useListParams`). */
export function FilterBar({ children, activeCount, onClear, className }: FilterBarProps) {
  return (
    <fieldset className={cn('m-0 flex min-w-0 flex-wrap items-center gap-2 border-0 p-0', className)}>
      <legend className="sr-only">{copy.filters.label}</legend>
      {children}
      {activeCount > 0 ? (
        <Button type="button" variant="ghost" size="sm" onClick={onClear}>
          <XIcon aria-hidden="true" />
          {copy.filters.clear}
        </Button>
      ) : null}
    </fieldset>
  )
}

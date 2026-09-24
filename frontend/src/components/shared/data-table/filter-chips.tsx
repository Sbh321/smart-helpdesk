import { XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'

/** One filter in force: what it restricts, to what, and how to drop it. */
export interface FilterChip {
  key: string
  /** The filter's name, e.g. "Status". */
  label: string
  /** The values in words, e.g. "Open, Assigned". */
  value: string
  onRemove: () => void
}

/**
 * The filters in force, each removable (roadmap M4-04). A list can carry ten filters behind menus, so
 * without this the only sign that a view is filtered is a count; the chips say which ones and let the
 * reader drop one without hunting for the menu it came from
 * (docs/06-design-system/page-patterns.md §List page).
 */
export function FilterChips({ chips }: { chips: readonly FilterChip[] }) {
  if (chips.length === 0) return null

  return (
    <ul aria-label={copy.filters.active} className="flex min-w-0 flex-wrap items-center gap-1.5">
      {chips.map((chip) => (
        <li key={chip.key}>
          <Button
            type="button"
            variant="outline"
            size="xs"
            onClick={chip.onRemove}
            aria-label={fill(copy.filters.remove, { filter: chip.label, value: chip.value })}
            className="gap-1 rounded-full font-normal"
          >
            <span className="text-muted-foreground">{chip.label}:</span>
            <span className="max-w-48 truncate">{chip.value}</span>
            <XIcon aria-hidden="true" className="size-3" />
          </Button>
        </li>
      ))}
    </ul>
  )
}

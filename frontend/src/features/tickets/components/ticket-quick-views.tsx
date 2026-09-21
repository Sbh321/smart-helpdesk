import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import type { SortSpec } from '@/lib/list-params'
import {
  ACTIVE_STATUS,
  ASSIGNED_TO_ME,
  type TicketFilters,
  type TicketListParams,
  ticketListSchema,
  UNASSIGNED,
} from '../api/ticket-queries'

type TicketSort = (typeof ticketListSchema.sortFields)[number]

export interface QuickView {
  id: 'all' | 'mine' | 'unassigned' | 'breaching'
  label: string
  filters: TicketFilters
  sort: SortSpec<TicketSort>
}

/**
 * The quick views of the ticket list (roadmap M2-11): presets of URL parameters, nothing more. "All" is
 * the list without filters or search, in its default order.
 */
export const QUICK_VIEWS: readonly QuickView[] = [
  { id: 'all', label: copy.tickets.quickViews.all, filters: {}, sort: ticketListSchema.defaultSort },
  {
    id: 'mine',
    label: copy.tickets.quickViews.mine,
    filters: { assignee_id: [ASSIGNED_TO_ME], status: [ACTIVE_STATUS] },
    sort: ticketListSchema.defaultSort,
  },
  {
    id: 'unassigned',
    label: copy.tickets.quickViews.unassigned,
    filters: { assignee_id: [UNASSIGNED], status: [ACTIVE_STATUS] },
    sort: ticketListSchema.defaultSort,
  },
  {
    id: 'breaching',
    label: copy.tickets.quickViews.breaching,
    filters: { sla_state: ['warning', 'breached'] },
    sort: 'sla_due_at',
  },
]

function sameValue(left: unknown, right: unknown): boolean {
  if (Array.isArray(left) && Array.isArray(right)) {
    return left.length === right.length && [...left].sort().join(',') === [...right].sort().join(',')
  }
  return JSON.stringify(left) === JSON.stringify(right)
}

/**
 * The view the URL is showing, or `undefined` for a hand-made combination. "All" matches any order
 * without filters; the other views need their filters exactly (in any value order) and their sort.
 */
export function activeQuickView(params: TicketListParams, views: readonly QuickView[] = QUICK_VIEWS) {
  const filters = Object.entries(params.filters).filter(([, value]) => value !== undefined)
  if (params.search) return undefined
  if (filters.length === 0) return views.find((view) => view.id === 'all')
  return views.find((view) => {
    const wanted = Object.entries(view.filters)
    return (
      view.id !== 'all' &&
      view.sort === params.sort &&
      wanted.length === filters.length &&
      wanted.every(([key, value]) => sameValue(value, (params.filters as Record<string, unknown>)[key]))
    )
  })
}

export interface TicketQuickViewsProps {
  params: TicketListParams
  /** Views to offer ("My tickets" only for a user with an Agent profile). */
  views: readonly QuickView[]
  onSelect: (view: QuickView) => void
}

/** A segmented row of toggle buttons; the pressed one is derived from the URL, never stored. */
export function TicketQuickViews({ params, views, onSelect }: TicketQuickViewsProps) {
  const active = activeQuickView(params, views)
  return (
    <div className="flex flex-wrap items-center gap-2">
      <fieldset className="m-0 inline-flex min-w-0 flex-wrap gap-1 rounded-lg border border-border bg-muted/40 p-1">
        <legend className="sr-only">{copy.tickets.quickViews.label}</legend>
        {views.map((view) => (
          <Button
            key={view.id}
            type="button"
            size="sm"
            variant={active?.id === view.id ? 'secondary' : 'ghost'}
            aria-pressed={active?.id === view.id}
            onClick={() => onSelect(view)}
          >
            {view.label}
          </Button>
        ))}
      </fieldset>
      {active === undefined ? (
        <span className="text-sm text-muted-foreground">{copy.tickets.quickViews.custom}</span>
      ) : null}
    </div>
  )
}

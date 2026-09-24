import { useQueries } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { KpiTile, kpiTileLinkClassName } from '@/components/shared/kpi-tile'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import {
  ACTIVE_STATUS,
  type TicketFilters,
  type TicketListParams,
  ticketListSchema,
  ticketQueries,
  UNASSIGNED,
} from '../api/ticket-queries'

const text = copy.dashboard.now

/**
 * The four live counts of the dashboard's first row (roadmap M4-08): open, unassigned, due soon and
 * breached. Each is the ticket list's own `meta.total` for exactly the filters its tile opens, so the
 * number on the dashboard is the number of rows the agent lands on. Nothing is counted in the browser.
 * The period does not apply: this is the queue now.
 */
const TILES: readonly {
  key: keyof typeof text.tiles
  filters: TicketFilters
  sort?: TicketListParams['sort']
}[] = [
  { key: 'open', filters: { status: [ACTIVE_STATUS] } },
  { key: 'unassigned', filters: { status: [ACTIVE_STATUS], assignee_id: [UNASSIGNED] } },
  { key: 'dueSoon', filters: { status: [ACTIVE_STATUS], sla_state: ['warning'] }, sort: 'sla_due_at' },
  { key: 'breached', filters: { status: [ACTIVE_STATUS], sla_state: ['breached'] }, sort: 'sla_due_at' },
]

function paramsFor(tile: (typeof TILES)[number]): TicketListParams {
  const defaults = ticketListSchema.parse({})
  return { ...defaults, filters: tile.filters, sort: tile.sort ?? defaults.sort }
}

export function TicketsRightNow({ workspace }: { workspace: string }) {
  const allowed = useCan('tickets.view')
  const tenantId = useSession().session?.tenant.id ?? ''
  const counts = useQueries({
    queries: TILES.map((tile) => ({
      ...ticketQueries.list(tenantId, {
        ...ticketListSchema.toApiQuery(paramsFor(tile)),
        page: 1,
        per_page: 1,
      }),
      enabled: allowed && tenantId !== '',
    })),
  })
  if (!allowed) return null

  return (
    <section aria-labelledby="dashboard-now" className="flex flex-col gap-3">
      <div className="flex flex-wrap items-baseline gap-x-3">
        <h2 id="dashboard-now" className="text-base font-semibold">
          {text.title}
        </h2>
        <p className="text-sm text-muted-foreground">{text.note}</p>
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {TILES.map((tile, index) => {
          const count = counts[index]
          const label = text.tiles[tile.key]
          if (!count || count.isPending) return <Skeleton key={tile.key} className="h-[5.5rem]" />
          return (
            <Link
              key={tile.key}
              to="/$workspace/tickets"
              params={{ workspace }}
              search={ticketListSchema.toSearch(paramsFor(tile))}
              className={kpiTileLinkClassName}
            >
              <KpiTile compact label={label} value={count.isError ? '—' : String(count.data.meta.total)} />
            </Link>
          )
        })}
      </div>
    </section>
  )
}

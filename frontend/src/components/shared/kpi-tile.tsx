import { ArrowDownRightIcon, ArrowRightIcon, ArrowUpRightIcon, MinusIcon } from 'lucide-react'
import { type ReactNode, useId } from 'react'
import { cn } from '@/lib/utils'

export interface KpiTileChange {
  direction: 'up' | 'down' | 'flat'
  /** The whole sentence, for example "Up 12% from 40". */
  text: string
}

export interface KpiTileProps {
  label: string
  /** The value, already formatted for its unit. */
  value: string
  /** Change against the previous period; `null` shows `noChange` instead. */
  change?: KpiTileChange | null
  /** Shown when there is no comparison (for example "No comparison"). */
  noChange?: string
  /** A link or action under the value, for example "Open the full report". */
  footer?: ReactNode
  /** A small trend under the value (`Sparkline`), only where the API returns the series. */
  sparkline?: ReactNode
  /** Tighter padding and a smaller value, for a dashboard row of many tiles. */
  compact?: boolean
  /** The tile's heading level; `h3` under a section `h2` by default. */
  headingLevel?: 'h2' | 'h3'
  className?: string
}

/**
 * For a tile that is itself the link to its detail (roadmap M4-08): wrap the tile in the router's `Link`
 * with this class. The whole tile is the target, the focus ring sits on its edge, and hover lifts the
 * border, so no "Open the full report" line is needed under every number.
 */
export const kpiTileLinkClassName =
  'block rounded-lg outline-hidden focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring [&>[data-slot=kpi-tile]]:transition-colors hover:[&>[data-slot=kpi-tile]]:border-ring motion-reduce:[&>[data-slot=kpi-tile]]:transition-none'

const ICONS = { up: ArrowUpRightIcon, down: ArrowDownRightIcon, flat: ArrowRightIcon } as const

/**
 * One key figure (docs/06-design-system/components.md §KpiTile): label, a large tabular value and the
 * change against the previous period in words — the arrow is decoration, never the only signal, and no
 * colour says "good" or "bad" because that depends on the measure (more tickets resolved is good, more
 * breaches is not).
 */
export function KpiTile({
  label,
  value,
  change,
  noChange,
  footer,
  sparkline,
  compact = false,
  headingLevel = 'h3',
  className,
}: KpiTileProps) {
  const headingId = useId()
  const Heading = headingLevel
  const Icon = change ? ICONS[change.direction] : MinusIcon
  return (
    <section
      aria-labelledby={headingId}
      data-slot="kpi-tile"
      className={cn(
        'flex h-full flex-col gap-1 rounded-lg border border-border bg-surface',
        compact ? 'p-3' : 'p-4',
        className,
      )}
    >
      <Heading id={headingId} className="text-sm font-medium text-muted-foreground">
        {label}
      </Heading>
      {/* The sparkline sits beside the value, so a tile with a trend is no taller than one without. */}
      <div className="flex items-end justify-between gap-3">
        <p className={cn('font-semibold tabular-nums tracking-tight', compact ? 'text-2xl' : 'text-3xl')}>
          {value}
        </p>
        {sparkline ? <div className="mb-1.5 w-24 min-w-0 shrink">{sparkline}</div> : null}
      </div>
      {change || noChange ? (
        <p className="flex items-center gap-1 text-sm text-muted-foreground">
          <Icon aria-hidden="true" className="size-4 shrink-0" />
          <span>{change ? change.text : noChange}</span>
        </p>
      ) : null}
      {footer ? <div className="mt-1 text-sm">{footer}</div> : null}
    </section>
  )
}

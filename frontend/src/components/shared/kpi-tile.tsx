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
  /** The tile's heading level; `h3` under a section `h2` by default. */
  headingLevel?: 'h2' | 'h3'
  className?: string
}

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
      className={cn('flex flex-col gap-1 rounded-lg border border-border bg-surface p-4', className)}
    >
      <Heading id={headingId} className="text-sm font-medium text-muted-foreground">
        {label}
      </Heading>
      <p className="text-3xl font-semibold tabular-nums tracking-tight">{value}</p>
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

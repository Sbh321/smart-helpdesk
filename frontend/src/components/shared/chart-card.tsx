import { TableIcon } from 'lucide-react'
import { type ReactNode, useId, useState } from 'react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export interface ChartCardProps {
  title: string
  description?: string
  /** Right side of the header, for example a link to the full report. */
  actions?: ReactNode
  /** The chart (Recharts with `accessibilityLayer`, so its points are reachable from the keyboard). */
  children: ReactNode
  /** The same numbers as a table: visually hidden until the toggle shows it, always in the page. */
  table: ReactNode
  /** Labels of the table toggle. */
  showTableLabel: string
  hideTableLabel: string
  headingLevel?: 'h2' | 'h3'
  className?: string
}

/**
 * A chart with its accessible table alternative (docs/06-design-system/accessibility.md: "charts have a
 * legend and a visually hidden data table"). The table is always rendered, so screen readers get the
 * numbers without opening anything; the toggle makes it visible for everyone else, and the print
 * stylesheet shows it on paper.
 */
export function ChartCard({
  title,
  description,
  actions,
  children,
  table,
  showTableLabel,
  hideTableLabel,
  headingLevel = 'h3',
  className,
}: ChartCardProps) {
  const headingId = useId()
  const tableId = useId()
  const [showTable, setShowTable] = useState(false)
  const Heading = headingLevel
  return (
    <section
      aria-labelledby={headingId}
      data-slot="chart-card"
      className={cn('flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-surface p-4', className)}
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex min-w-0 flex-col gap-0.5">
          <Heading id={headingId} className="text-sm font-semibold">
            {title}
          </Heading>
          {description ? <p className="text-xs text-muted-foreground">{description}</p> : null}
        </div>
        <div className="flex items-center gap-1 print:hidden">
          {actions}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-expanded={showTable}
            aria-controls={tableId}
            onClick={() => setShowTable((shown) => !shown)}
          >
            <TableIcon aria-hidden="true" />
            {showTable ? hideTableLabel : showTableLabel}
          </Button>
        </div>
      </div>
      <div className="min-w-0">{children}</div>
      <div
        id={tableId}
        data-slot="chart-table"
        className={cn(showTable ? 'block' : 'sr-only print:not-sr-only')}
      >
        {table}
      </div>
    </section>
  )
}

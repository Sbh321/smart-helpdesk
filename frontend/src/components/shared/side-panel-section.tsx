import { ChevronRightIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export interface SidePanelSectionProps {
  title: string
  /** A short value shown next to the title while the section is closed, e.g. the assignee's name. */
  summary?: ReactNode
  /** Open on first render; the viewer's later choice is not persisted (a record page is short-lived). */
  defaultOpen?: boolean
  /** Sections that always matter (status, requester) render open and without a toggle. */
  static?: boolean
  children: ReactNode
  className?: string
}

/**
 * One collapsible block of a record page's context panel (docs/06-design-system/page-patterns.md
 * §Record page). Built on `details`/`summary`, so open and close work with the keyboard and are
 * announced without any ARIA of our own; the marker is replaced by a rotating chevron.
 *
 * Progressive disclosure (principle D5): the panel shows what the agent needs constantly and keeps the
 * rest one keystroke away, instead of a definition list where a breached SLA and an empty tag list
 * carry the same weight.
 */
export function SidePanelSection({
  title,
  summary,
  defaultOpen = false,
  static: isStatic = false,
  children,
  className,
}: SidePanelSectionProps) {
  const heading = (
    <span className="flex min-w-0 items-center gap-2">
      <span className="font-medium text-sm">{title}</span>
      {summary ? (
        <span className="truncate text-muted-foreground text-sm group-open/section:hidden">{summary}</span>
      ) : null}
    </span>
  )

  if (isStatic) {
    return (
      <section className={cn('border-border border-b px-3 py-2.5 last:border-b-0', className)}>
        <h3 className="mb-2 flex items-center gap-2">{heading}</h3>
        {children}
      </section>
    )
  }

  return (
    <details
      open={defaultOpen}
      className={cn('group/section border-border border-b last:border-b-0', className)}
    >
      <summary className="flex cursor-default list-none items-center gap-2 px-3 py-2.5 outline-hidden hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-2 focus-visible:outline-ring focus-visible:-outline-offset-2 [&::-webkit-details-marker]:hidden">
        <ChevronRightIcon
          aria-hidden="true"
          className="size-4 shrink-0 text-muted-foreground transition-transform group-open/section:rotate-90 motion-reduce:transition-none"
        />
        {heading}
      </summary>
      <div className="px-3 pb-3">{children}</div>
    </details>
  )
}

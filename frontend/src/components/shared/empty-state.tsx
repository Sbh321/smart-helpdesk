import type { LucideIcon } from 'lucide-react'
import { InboxIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia } from '@/components/ui/empty'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

export interface EmptyStateProps {
  title?: string
  description?: string
  icon?: LucideIcon
  /** A primary action; usually a `Button` or a `Link` rendered as one. */
  action?: ReactNode
  className?: string
}

/**
 * "There is nothing here, and that is fine" (docs/06-design-system/components.md §EmptyState).
 * The heading is an `h2`, because the page's `h1` belongs to `PageHeader`.
 */
export function EmptyState({
  title = copy.states.empty.title,
  description,
  icon: Icon = InboxIcon,
  action,
  className,
}: EmptyStateProps) {
  return (
    <Empty className={cn('border border-dashed border-border bg-surface', className)}>
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <Icon aria-hidden="true" />
        </EmptyMedia>
        <h2 className="text-sm font-medium tracking-tight">{title}</h2>
        {description ? <EmptyDescription>{description}</EmptyDescription> : null}
      </EmptyHeader>
      {action ? <EmptyContent>{action}</EmptyContent> : null}
    </Empty>
  )
}

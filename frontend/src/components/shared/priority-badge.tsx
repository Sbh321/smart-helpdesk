import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

export const PRIORITY_LEVELS = ['P1', 'P2', 'P3', 'P4'] as const
export type PriorityLevel = (typeof PRIORITY_LEVELS)[number]

const TINT: Record<PriorityLevel, string> = {
  P1: 'bg-priority-p1 text-priority-p1-foreground',
  P2: 'bg-priority-p2 text-priority-p2-foreground',
  P3: 'bg-priority-p3 text-priority-p3-foreground',
  P4: 'bg-priority-p4 text-priority-p4-foreground',
}

export function isPriorityLevel(value: string): value is PriorityLevel {
  return (PRIORITY_LEVELS as readonly string[]).includes(value)
}

/**
 * Solid priority badge, `P1 Critical` … `P4 Low` (docs/06-design-system/components.md §PriorityBadge).
 * The level is always written out, so the colour is never the only signal. The explanation popover and
 * the "manual" marker arrive with the ticket page (M2).
 */
export function PriorityBadge({ level, className }: { level: string; className?: string }) {
  if (!isPriorityLevel(level)) {
    return <span className={cn('text-xs', className)}>{level}</span>
  }
  return (
    <span
      className={cn(
        'inline-flex h-5 w-fit items-center rounded-badge px-2 text-xs font-semibold whitespace-nowrap',
        TINT[level],
        className,
      )}
    >
      {copy.tickets.priority[level]}
    </span>
  )
}

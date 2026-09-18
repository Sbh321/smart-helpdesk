import type { LucideIcon } from 'lucide-react'
import { ArchiveIcon, CheckCircleIcon, CircleIcon, ClockIcon, LoaderIcon, UserCheckIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

export const TICKET_STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'] as const
export type TicketStatus = (typeof TICKET_STATUSES)[number]

const STYLE: Record<TicketStatus, { icon: LucideIcon; className: string }> = {
  open: { icon: CircleIcon, className: 'bg-status-open text-status-open-foreground' },
  assigned: { icon: UserCheckIcon, className: 'bg-status-assigned text-status-assigned-foreground' },
  in_progress: { icon: LoaderIcon, className: 'bg-status-in-progress text-status-in-progress-foreground' },
  pending: { icon: ClockIcon, className: 'bg-status-pending text-status-pending-foreground' },
  resolved: { icon: CheckCircleIcon, className: 'bg-status-resolved text-status-resolved-foreground' },
  closed: { icon: ArchiveIcon, className: 'bg-status-closed text-status-closed-foreground' },
}

export function isTicketStatus(value: string): value is TicketStatus {
  return (TICKET_STATUSES as readonly string[]).includes(value)
}

/**
 * Tinted status badge with an icon, so the status never depends on colour alone
 * (docs/06-design-system/components.md §StatusBadge, tokens.md `--status-*`).
 */
export function StatusBadge({ status, className }: { status: string; className?: string }) {
  if (!isTicketStatus(status)) {
    return <span className={cn('text-xs', className)}>{status}</span>
  }
  const { icon: Icon, className: tint } = STYLE[status]
  return (
    <span
      className={cn(
        'inline-flex h-5 w-fit items-center gap-1 rounded-badge px-2 text-xs font-medium whitespace-nowrap',
        tint,
        className,
      )}
    >
      <Icon aria-hidden="true" className="size-3" />
      {copy.tickets.status[status]}
    </span>
  )
}

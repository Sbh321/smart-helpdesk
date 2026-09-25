import { CircleCheckIcon, CircleXIcon, ClockIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'
import type { PaymentStatus } from '../api'

const APPEARANCE = {
  pending: { icon: ClockIcon, tone: 'text-warning' },
  approved: { icon: CircleCheckIcon, tone: 'text-success' },
  rejected: { icon: CircleXIcon, tone: 'text-destructive' },
} as const

/** A payment's review state as an icon and a word. */
export function PaymentStatusBadge({ status, className }: { status: PaymentStatus; className?: string }) {
  const { icon: Icon, tone } = APPEARANCE[status]
  return (
    <span className={cn('inline-flex items-center gap-1.5 font-medium', tone, className)}>
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {copy.platform.payments.statuses[status]}
    </span>
  )
}

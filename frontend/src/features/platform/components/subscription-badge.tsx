import {
  CircleCheckIcon,
  CircleDashedIcon,
  HourglassIcon,
  LockIcon,
  type LucideIcon,
  SparklesIcon,
} from 'lucide-react'
import { copy, fill } from '@/copy/en'
import { cn } from '@/lib/utils'
import type { Subscription, SubscriptionState } from '../api'

const APPEARANCE: Record<SubscriptionState, { icon: LucideIcon; tone: string }> = {
  trialing: { icon: SparklesIcon, tone: 'text-info' },
  active: { icon: CircleCheckIcon, tone: 'text-success' },
  grace: { icon: HourglassIcon, tone: 'text-warning' },
  expired: { icon: LockIcon, tone: 'text-destructive' },
  none: { icon: CircleDashedIcon, tone: 'text-muted-foreground' },
}

/** A subscription's state as an icon and a word, never colour alone (G4). */
export function SubscriptionBadge({ state, className }: { state: SubscriptionState; className?: string }) {
  const { icon: Icon, tone } = APPEARANCE[state]
  return (
    <span className={cn('inline-flex items-center gap-1.5 font-medium', tone, className)}>
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {copy.platform.states[state]}
    </span>
  )
}

/** "9 days left", "3 days of grace left", or nothing. */
export function daysLeftText(subscription: Pick<Subscription, 'state' | 'days_left'>): string | null {
  const days = subscription.days_left
  if (days === null) return null
  if (subscription.state === 'grace') return fill(copy.platform.graceLeft, { count: days })
  return days === 1 ? copy.platform.oneDayLeft : fill(copy.platform.daysLeft, { count: days })
}

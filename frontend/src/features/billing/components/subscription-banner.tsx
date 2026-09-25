import { Link } from '@tanstack/react-router'
import { HourglassIcon, LockIcon, SparklesIcon } from 'lucide-react'
import { buttonVariants } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { cn } from '@/lib/utils'

const text = copy.billing.banner
/** The trial or subscription ending shows from this many days before. */
const WARN_FROM_DAYS = 7

/**
 * The subscription in the shell (ADR-0025 §6): a trial or subscription ending within a week, the grace
 * period, and read-only; nothing otherwise. People who manage billing get a link to it; others are told
 * whom to ask.
 */
export function SubscriptionBanner({ workspace }: { workspace: string }) {
  const { session } = useSession()
  const canManage = useCan('billing.manage')
  const subscription = session?.tenant.subscription
  if (!subscription) return null
  const zone = session?.tenant.timezone ?? 'UTC'
  const days = subscription.days_left ?? 0

  let tone: 'info' | 'warning' | 'destructive'
  let message: string
  let Icon = SparklesIcon
  if (subscription.state === 'expired') {
    tone = 'destructive'
    message = text.readOnly
    Icon = LockIcon
  } else if (subscription.state === 'grace') {
    tone = 'warning'
    message = fill(text.grace, {
      date: subscription.grace_ends_at ? formatInZone(subscription.grace_ends_at, zone, 'd MMMM') : '',
    })
    Icon = HourglassIcon
  } else if (
    (subscription.state === 'trialing' || subscription.state === 'active') &&
    days <= WARN_FROM_DAYS
  ) {
    tone = 'info'
    const trial = subscription.state === 'trialing'
    message =
      days <= 1
        ? trial
          ? text.trialLastDay
          : text.endingLastDay
        : fill(trial ? text.trial : text.ending, { count: days })
  } else {
    return null
  }

  return (
    <section
      aria-label={text.label}
      className={cn(
        'flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-6 py-2.5 text-sm',
        tone === 'destructive' && 'border-destructive/30 bg-destructive/10',
        tone === 'warning' && 'border-warning/30 bg-warning/10',
        tone === 'info' && 'border-info/30 bg-info/10',
      )}
    >
      <p className="flex min-w-0 flex-1 items-center gap-2 font-medium">
        <Icon
          aria-hidden="true"
          className={cn(
            'size-4 shrink-0',
            tone === 'destructive' ? 'text-destructive' : tone === 'warning' ? 'text-warning' : 'text-info',
          )}
        />
        {message}
      </p>
      {canManage ? (
        <Link
          to="/$workspace/settings/billing"
          params={{ workspace }}
          className={buttonVariants({ size: 'sm', variant: 'outline' })}
        >
          {text.action}
        </Link>
      ) : (
        <span className="text-muted-foreground">{text.askOwner}</span>
      )}
    </section>
  )
}

import type { LucideIcon } from 'lucide-react'
import { CircleCheckIcon, ClockIcon, OctagonAlertIcon, PauseIcon, TriangleAlertIcon } from 'lucide-react'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { durationBetween, formatInZone } from '@/lib/datetime/format'
import { cn } from '@/lib/utils'

/** A timer's state as the API stores it; `cancelled` and a missing timer read as "no SLA". */
export type SlaState = 'running' | 'warning' | 'breached' | 'paused' | 'met' | 'cancelled' | null | undefined
export type SlaKind = 'first_response' | 'resolution'

const text = copy.sla.indicator

/**
 * Each state has its own icon **shape** as well as its colour and word, so the five stay apart in
 * greyscale and for a colour-blind reader: clock (on track), triangle (due soon), octagon (breached),
 * pause bars (paused), tick (met). docs/06-design-system/accessibility.md.
 */
const APPEARANCE: Record<
  Exclude<SlaState, 'cancelled' | null | undefined>,
  { icon: LucideIcon; tone: string }
> = {
  running: { icon: ClockIcon, tone: 'text-sla-ok' },
  warning: { icon: TriangleAlertIcon, tone: 'text-sla-warning' },
  breached: { icon: OctagonAlertIcon, tone: 'text-sla-breached font-medium' },
  paused: { icon: PauseIcon, tone: 'text-sla-paused' },
  met: { icon: CircleCheckIcon, tone: 'text-sla-ok' },
}

/**
 * The time part of an indicator: time left or overdue, in words. `null` when the clock is not running:
 * a paused timer has no countdown (it would imply the clock runs, G3) and a met one has nothing left.
 */
export function slaRemaining(state: SlaState, dueAt: string | null, now: Date): string | null {
  if (dueAt === null || (state !== 'running' && state !== 'warning' && state !== 'breached')) return null
  const overdue = state === 'breached' || new Date(dueAt).getTime() <= now.getTime()
  return fill(overdue ? text.overdueBy : text.dueIn, { duration: durationBetween(dueAt, now.toISOString()) })
}

/**
 * One SLA timer in one line (roadmap M4-07), the same in the ticket queue, the ticket header and the
 * SLA panel. The state is carried by colour **and** icon **and** word; `kind` labels a response or a
 * resolution timer; the exact due time is in the tooltip.
 *
 * `compact` (the queue) shows only the time in words and keeps the state word for screen readers; the
 * icon still tells the states apart. The full form writes both: "Due soon · 20 minutes left".
 */
export function SlaIndicator({
  state,
  dueAt,
  timeZone,
  now = new Date(),
  kind,
  compact = false,
  className,
}: {
  state: SlaState
  dueAt: string | null
  timeZone: string
  now?: Date
  kind?: SlaKind
  compact?: boolean
  className?: string
}) {
  if (state === null || state === undefined || state === 'cancelled') {
    return <span className={cn('text-sm text-muted-foreground', className)}>{text.none}</span>
  }

  const { icon: Icon, tone } = APPEARANCE[state]
  const word = text.states[state]
  const remaining = slaRemaining(state, dueAt, now)

  return (
    <Hint label={dueAt ? fill(text.dueAt, { time: formatInZone(dueAt, timeZone) }) : undefined}>
      <span className={cn('flex min-w-0 items-center gap-1.5 text-sm', tone, className)}>
        <Icon aria-hidden="true" className="size-3.5 shrink-0" />
        {kind ? <span className="shrink-0 text-muted-foreground">{text.kinds[kind]}</span> : null}
        {compact && remaining ? (
          <>
            <span className="truncate">{remaining}</span>
            <span className="sr-only">{word}</span>
          </>
        ) : (
          <span className="truncate">{remaining ? fill(text.full, { state: word, remaining }) : word}</span>
        )}
      </span>
    </Hint>
  )
}

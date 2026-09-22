import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { type SlaTimer, slaQueries } from '../api/sla-queries'

function remaining(timer: SlaTimer, now: number): string {
  if (timer.state === 'met' || timer.state === 'cancelled') return copy.sla.finished
  if (timer.state === 'paused') return copy.sla.paused
  const seconds = Math.max(0, Math.ceil((new Date(timer.due_at).getTime() - now) / 1000))
  if (seconds === 0) return copy.sla.overdue
  // Rounded up to whole minutes first, so 1 h 59 min 30 s reads "2h 0m", never "1h 60m".
  const totalMinutes = Math.ceil(seconds / 60)
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  return hours > 0 ? fill(copy.sla.hoursMinutes, { hours, minutes }) : fill(copy.sla.minutesOnly, { minutes })
}

/** The SLA state tokens of docs/06-design-system (tokens.css `--sla-*`); the state is always written out too. */
const STATE_TINT: Record<SlaTimer['state'], string> = {
  running: 'bg-sla-ok text-sla-ok-foreground',
  warning: 'bg-sla-warning text-sla-warning-foreground',
  breached: 'bg-sla-breached text-sla-breached-foreground',
  paused: 'bg-sla-paused text-sla-paused-foreground',
  met: 'bg-muted',
  cancelled: 'bg-muted',
}

export function TicketSlaPanel({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const [now, setNow] = useState(() => Date.now())
  const result = useQuery({ ...slaQueries.ticket(tenantId, ticketId), enabled: tenantId !== '' })

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 30_000)
    return () => window.clearInterval(timer)
  }, [])

  if (result.isPending) return <Skeleton className="h-24 w-full" />
  if (result.isError) return <ErrorState error={result.error} onRetry={() => void result.refetch()} />
  if (result.data.length === 0)
    return <p className="text-sm text-muted-foreground">{copy.tickets.detail.slaPending}</p>

  return (
    <ul className="space-y-3">
      {result.data.map((timer) => (
        <li key={timer.id} className="rounded-md border border-border p-3 text-sm">
          <div className="flex items-center justify-between gap-2">
            <span className="font-medium">
              {timer.kind === 'first_response' ? copy.sla.firstResponse : copy.sla.resolution}
            </span>
            <span className={`rounded px-2 py-0.5 ${STATE_TINT[timer.state]}`}>
              {copy.sla.timerStates[timer.state]}
            </span>
          </div>
          <p className="mt-2 text-muted-foreground">
            {fill(copy.sla.dueAt, { time: formatInZone(timer.due_at, timeZone) })}
          </p>
          {timer.state === 'met' || timer.state === 'cancelled' ? null : (
            <p className="mt-1 tabular-nums" aria-live="polite" aria-atomic="true">
              {timer.state === 'paused'
                ? copy.sla.paused
                : timer.state === 'breached' || new Date(timer.due_at).getTime() <= now
                  ? copy.sla.overdue
                  : fill(copy.sla.remainingTime, { time: remaining(timer, now) })}
            </p>
          )}
          <p className="mt-1 text-xs text-muted-foreground">
            {fill(copy.sla.strategy, { name: timer.strategy, version: timer.strategy_version })}
          </p>
        </li>
      ))}
    </ul>
  )
}

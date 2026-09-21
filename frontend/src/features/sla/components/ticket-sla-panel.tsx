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
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.ceil((seconds % 3600) / 60)
  return hours > 0 ? fill(copy.sla.hoursMinutes, { hours, minutes }) : fill(copy.sla.minutesOnly, { minutes })
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
            <span className="rounded bg-muted px-2 py-0.5">{copy.sla.timerStates[timer.state]}</span>
          </div>
          <p className="mt-2 text-muted-foreground">
            {fill(copy.sla.dueAt, { time: formatInZone(timer.due_at, timeZone) })}
          </p>
          <p className="mt-1 tabular-nums" aria-live="polite" aria-atomic="true">
            {fill(copy.sla.remainingTime, { time: remaining(timer, now) })}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {fill(copy.sla.strategy, { name: timer.strategy, version: timer.strategy_version })}
          </p>
        </li>
      ))}
    </ul>
  )
}

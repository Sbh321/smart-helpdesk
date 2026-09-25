import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { SlaIndicator } from '@/components/shared/sla-indicator'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { durationBetween, formatInZone } from '@/lib/datetime/format'
import { type SlaTimer, slaQueries } from '../api/sla-queries'

const text = copy.sla.panel
const ACTIVE: ReadonlySet<SlaTimer['state']> = new Set(['running', 'warning', 'breached', 'paused'])

/** "Now", moved on every 30 s so remaining times stay current without a per-second re-render. */
function useNow(intervalMs = 30_000): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), intervalMs)
    return () => window.clearInterval(timer)
  }, [intervalMs])
  return now
}

/** The current cycle of each timer kind, response first. Earlier cycles (a reopened ticket) are history. */
export function currentTimers(timers: readonly SlaTimer[]): SlaTimer[] {
  const latest = new Map<SlaTimer['kind'], SlaTimer>()
  for (const timer of timers) {
    const seen = latest.get(timer.kind)
    if (!seen || timer.cycle > seen.cycle) latest.set(timer.kind, timer)
  }
  return (['first_response', 'resolution'] as const).flatMap((kind) => latest.get(kind) ?? [])
}

/** A span of working time in words ("4 hours"), from minutes or seconds. */
function span(seconds: number): string {
  return durationBetween(new Date(0).toISOString(), new Date(seconds * 1000).toISOString())
}

/** When the state last changed, in the words that fit it. */
function milestone(timer: SlaTimer, timeZone: string): string {
  const at = (iso: string) => formatInZone(iso, timeZone)
  if (timer.state === 'met' && timer.met_at) return fill(text.metAt, { time: at(timer.met_at) })
  if (timer.state === 'paused' && timer.paused_at)
    return fill(text.pausedSince, { time: at(timer.paused_at) })
  if (timer.state === 'cancelled' && timer.cancelled_at)
    return fill(text.cancelledAt, { time: at(timer.cancelled_at) })
  return fill(copy.sla.indicator.dueAt, { time: at(timer.due_at) })
}

/**
 * The ticket header's SLA line (roadmap M4-07): every timer whose clock matters now, each labelled
 * Response or Resolution, so "due in 20 minutes" never leaves the reader guessing which promise it is.
 * Nothing when every timer has finished.
 */
export function TicketSlaSummary({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const now = useNow()
  const result = useQuery({ ...slaQueries.ticket(tenantId, ticketId), enabled: tenantId !== '' })
  const active = currentTimers(result.data ?? []).filter((timer) => ACTIVE.has(timer.state))
  if (active.length === 0) return null
  return (
    <ul aria-label={text.summaryLabel} className="flex flex-wrap items-center gap-x-4 gap-y-1">
      {active.map((timer) => (
        <li key={timer.id}>
          <SlaIndicator
            state={timer.state}
            dueAt={timer.due_at}
            timeZone={timeZone}
            now={now}
            kind={timer.kind}
          />
        </li>
      ))}
    </ul>
  )
}

/**
 * The SLA section of the ticket's context panel (roadmap M4-07): per timer kind, its state in the
 * shared `SlaIndicator`, the moment that matters (due, paused since, met), and behind a disclosure the
 * policy, version and Business calendar that produced the deadline. Timers keep the calendar and policy
 * version they started with (docs/04-domain/sla.md), so the disclosure names those, not today's policy.
 *
 * Screen readers hear a state change once, through a polite live region holding only the states; the
 * remaining time updates silently every 30 s rather than being announced on every tick.
 */
export function TicketSlaPanel({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const now = useNow()
  const result = useQuery({ ...slaQueries.ticket(tenantId, ticketId), enabled: tenantId !== '' })
  const policies = useQuery({ ...slaQueries.policies(tenantId), enabled: tenantId !== '', staleTime: 60_000 })
  const calendars = useQuery({
    ...slaQueries.calendars(tenantId),
    enabled: tenantId !== '',
    staleTime: 60_000,
  })

  if (result.isPending) return <Skeleton className="h-24 w-full" />
  if (result.isError) return <ErrorState error={result.error} onRetry={() => void result.refetch()} />
  const timers = currentTimers(result.data)
  if (timers.length === 0)
    return <p className="text-sm text-muted-foreground">{copy.tickets.detail.slaPending}</p>

  const policyName = (id: string) =>
    policies.data?.find((policy) => policy.id === id)?.name ?? text.unknownPolicy
  const calendarLine = (id: string | null) => {
    if (id === null) return copy.sla.alwaysOpen
    const calendar = calendars.data?.find((row) => row.id === id)
    return calendar
      ? fill(copy.sla.calendarLine, { name: calendar.name, zone: calendar.timezone })
      : text.unknownCalendar
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="sr-only" aria-live="polite" aria-atomic="true">
        {timers
          .map((timer) =>
            fill(text.announce, {
              kind: copy.sla.indicator.kinds[timer.kind],
              state:
                timer.state === 'cancelled'
                  ? copy.sla.timerStates.cancelled
                  : copy.sla.indicator.states[timer.state],
            }),
          )
          .join(' ')}
      </p>
      <ul className="flex flex-col gap-3">
        {timers.map((timer) => (
          <li key={timer.id} className="rounded-md border border-border p-3 text-sm bg-surface">
            <h3 className="font-medium">
              {timer.kind === 'first_response' ? copy.sla.firstResponse : copy.sla.resolution}
            </h3>
            {timer.state === 'cancelled' ? (
              <p className="mt-1 text-muted-foreground">{copy.sla.timerStates.cancelled}</p>
            ) : (
              <SlaIndicator
                className="mt-1"
                state={timer.state}
                dueAt={timer.due_at}
                timeZone={timeZone}
                now={now}
              />
            )}
            <p className="mt-1 text-muted-foreground">{milestone(timer, timeZone)}</p>
            {timer.state === 'paused' ? (
              <p className="mt-1 text-muted-foreground">{text.pausedHint}</p>
            ) : null}
            <details className="mt-2">
              <summary className="cursor-default text-muted-foreground hover:text-foreground">
                {text.details}
              </summary>
              <dl className="mt-2 grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1">
                <dt className="text-muted-foreground">{text.policy}</dt>
                <dd>
                  {fill(text.policyVersion, {
                    name: policyName(timer.policy_id),
                    version: timer.policy_version,
                  })}
                </dd>
                <dt className="text-muted-foreground">{copy.sla.calendar}</dt>
                <dd>{calendarLine(timer.calendar_id)}</dd>
                <dt className="text-muted-foreground">{text.target}</dt>
                <dd>{fill(text.targetValue, { duration: span(timer.target_minutes * 60) })}</dd>
                <dt className="text-muted-foreground">{text.warnsAt}</dt>
                <dd>{formatInZone(timer.warning_at, timeZone)}</dd>
                {timer.paused_total_seconds > 0 ? (
                  <>
                    <dt className="text-muted-foreground">{text.pausedTotal}</dt>
                    <dd>{span(timer.paused_total_seconds)}</dd>
                  </>
                ) : null}
                <dt className="text-muted-foreground">{text.strategy}</dt>
                <dd>{fill(copy.sla.strategy, { name: timer.strategy, version: timer.strategy_version })}</dd>
              </dl>
            </details>
          </li>
        ))}
      </ul>
    </div>
  )
}

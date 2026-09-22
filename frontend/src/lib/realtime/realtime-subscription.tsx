import { useEcho } from '@laravel/echo-react'
import { type QueryKey, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef } from 'react'
import { useRealtime } from './realtime-context'

/** Events arriving within this window cause one refetch (a bulk change sends one event per ticket). */
export const REALTIME_COALESCE_MS = 150

export interface RealtimeSubscriptionProps {
  /** A name from `realtimeChannels`; empty while the session is loading. */
  channel: string
  events: readonly string[]
  /** Query key prefixes to invalidate; the payload is only ids, so the data comes from the API. */
  invalidate: readonly QueryKey[]
}

/**
 * Subscribes to a private channel while live updates are on and invalidates queries when an event
 * arrives, and once more when the socket comes back after a drop (missed events are not replayed).
 * Renders nothing; with live updates off it does not subscribe at all.
 */
export function RealtimeSubscription(props: RealtimeSubscriptionProps) {
  const { active } = useRealtime()
  if (!active || props.channel === '') {
    return null
  }
  return <Listener {...props} />
}

function Listener({ channel, events, invalidate }: RealtimeSubscriptionProps) {
  const client = useQueryClient()
  const { state } = useRealtime()
  const keys = useRef(invalidate)
  keys.current = invalidate
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const previous = useRef(state)

  const refetch = useCallback(() => {
    if (timer.current !== null) return
    timer.current = setTimeout(() => {
      timer.current = null
      for (const queryKey of keys.current) {
        void client.invalidateQueries({ queryKey })
      }
    }, REALTIME_COALESCE_MS)
  }, [client])

  useEffect(
    () => () => {
      if (timer.current !== null) clearTimeout(timer.current)
    },
    [],
  )

  // Back after a drop: events sent while the socket was down are lost, so catch up once.
  useEffect(() => {
    if (state === 'connected' && (previous.current === 'offline' || previous.current === 'reconnecting')) {
      refetch()
    }
    previous.current = state
  }, [state, refetch])

  useEcho(channel, [...events], refetch, [refetch])

  return null
}

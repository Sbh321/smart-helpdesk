import { createContext, useContext } from 'react'

/**
 * `off`: this deployment or workspace has no live updates; the SPA polls as in the MVP.
 * `connecting` the first time, `reconnecting` after a drop, `offline` when Reverb is unreachable
 * (Pusher's `unavailable`/`failed`): polling carries on in the meantime.
 */
export type RealtimeState = 'off' | 'connecting' | 'connected' | 'reconnecting' | 'offline'

export interface RealtimeContextValue {
  state: RealtimeState
  /** Echo is configured, so subscriptions may mount (they refetch; polling never stops). */
  active: boolean
}

export const RealtimeContext = createContext<RealtimeContextValue>({ state: 'off', active: false })

export function useRealtime(): RealtimeContextValue {
  return useContext(RealtimeContext)
}

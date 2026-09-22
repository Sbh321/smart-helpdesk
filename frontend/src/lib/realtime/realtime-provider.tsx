import { configureEcho, echo, echoIsConfigured, useConnectionStatus } from '@laravel/echo-react'
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react'
import { useSession } from '@/lib/auth'
import type { RuntimeConfig } from '@/lib/config'
import { realtimeEchoOptions } from './echo-options'
import { RealtimeContext, type RealtimeState } from './realtime-context'

/**
 * Live updates for the signed-in workspace (M3-16, docs/03-architecture/realtime.md §Client). On only
 * when config.json has Reverb (`realtime.enabled`, set by the Compose profile `realtime`) and the
 * workspace has `features.realtime`. Children subscribe with <RealtimeSubscription>; everything keeps
 * its polling interval, so a dropped socket costs latency, never data.
 */
export function RealtimeProvider({ config, children }: { config: RuntimeConfig; children: ReactNode }) {
  const { session } = useSession()
  const workspaceOn = session?.tenant.features.realtime === true
  const options = useMemo(() => (workspaceOn ? realtimeEchoOptions(config) : null), [config, workspaceOn])
  const [configured, setConfigured] = useState(false)
  const [state, setState] = useState<RealtimeState>('off')

  useEffect(() => {
    if (options === null) {
      return
    }
    configureEcho(options)
    setConfigured(true)
    setState('connecting')
    return () => {
      setConfigured(false)
      setState('off')
      if (echoIsConfigured()) {
        echo().disconnect()
      }
    }
  }, [options])

  const value = useMemo(() => ({ state, active: configured }), [state, configured])

  return (
    <RealtimeContext value={value}>
      {configured ? <ConnectionWatcher onChange={setState} /> : null}
      {children}
    </RealtimeContext>
  )
}

/** Maps Echo's connection status to the indicator's states; `reconnecting` once it has been connected. */
function ConnectionWatcher({ onChange }: { onChange: (state: RealtimeState) => void }) {
  const status = useConnectionStatus()
  const wasConnected = useRef(false)

  useEffect(() => {
    if (status === 'connected') {
      wasConnected.current = true
      onChange('connected')
    } else if (status === 'connecting' || status === 'reconnecting') {
      onChange(wasConnected.current ? 'reconnecting' : 'connecting')
    } else {
      onChange('offline')
    }
  }, [status, onChange])

  return null
}

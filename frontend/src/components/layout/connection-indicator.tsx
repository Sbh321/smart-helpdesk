import { RadioIcon, RefreshCwIcon, WifiOffIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Hint } from '@/components/ui/tooltip'
import { copy } from '@/copy/en'
import { type RealtimeState, useRealtime } from '@/lib/realtime'
import { cn } from '@/lib/utils'

const text = copy.shell.realtime

/** A state must hold this long before it is announced, so a flapping socket stays quiet. */
export const ANNOUNCE_AFTER_MS = 2_000

const icons = {
  connected: RadioIcon,
  connecting: RefreshCwIcon,
  reconnecting: RefreshCwIcon,
  offline: WifiOffIcon,
}

/**
 * Live-update status in the topbar (M3-16): nothing while live updates are off, otherwise an icon with
 * its state as accessible name and tooltip (shape and text, never colour alone). Only going offline
 * and coming back after a drop are announced, once the state has held for two seconds.
 */
export function ConnectionIndicator() {
  const { state } = useRealtime()
  const announcement = useSettledAnnouncement(state)

  if (state === 'off') {
    return null
  }
  const Icon = icons[state]
  const label = text[state]

  return (
    <>
      <Hint label={label}>
        <span
          role="img"
          aria-label={label}
          data-state={state}
          className="inline-flex size-8 items-center justify-center rounded-md"
        >
          <Icon
            aria-hidden="true"
            className={cn(
              'size-4',
              state === 'connected' && 'text-success',
              state === 'offline' && 'text-muted-foreground',
              (state === 'connecting' || state === 'reconnecting') &&
                'text-muted-foreground motion-safe:animate-spin',
            )}
          />
        </span>
      </Hint>
      <span className="sr-only" role="status" aria-live="polite">
        {announcement}
      </span>
    </>
  )
}

function useSettledAnnouncement(state: RealtimeState): string {
  const [announcement, setAnnouncement] = useState('')
  const settled = useRef<RealtimeState>('off')

  useEffect(() => {
    const timer = setTimeout(() => {
      const previous = settled.current
      settled.current = state
      if (state === 'offline' && previous !== 'offline') {
        setAnnouncement(text.offline)
      } else if (state === 'connected' && (previous === 'offline' || previous === 'reconnecting')) {
        setAnnouncement(text.connected)
      }
    }, ANNOUNCE_AFTER_MS)
    return () => clearTimeout(timer)
  }, [state])

  return announcement
}

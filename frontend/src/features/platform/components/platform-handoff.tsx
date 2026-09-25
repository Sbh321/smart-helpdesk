import { useCallback, useEffect, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { copy } from '@/copy/en'
import { type PlatformTarget, platformHandoff } from '../api'

/**
 * The console's way into a platform-only host, the documentation or monitoring (M5-01, ADR-0024 / M5-06).
 * The header buttons open this page in a new tab, and those hosts send a visitor without a pass here too.
 * It asks the API for a one-time hand-off link and follows it; the `_platform` route has already required
 * a signed-in admin and returns here after sign-in.
 */
export function PlatformHandoff({ target, next }: { target: PlatformTarget; next?: string }) {
  const [error, setError] = useState<unknown>(null)
  const text = copy.platform.handoff[target]

  const handOff = useCallback(async () => {
    setError(null)
    try {
      window.location.replace(await platformHandoff(target, next ?? '/'))
    } catch (failure) {
      setError(failure)
    }
  }, [target, next])

  useEffect(() => {
    void handOff()
  }, [handOff])

  return error ? (
    <ErrorState error={error} title={text.failed} onRetry={() => void handOff()} />
  ) : (
    <p role="status" className="text-muted-foreground text-sm">
      {text.opening}
    </p>
  )
}

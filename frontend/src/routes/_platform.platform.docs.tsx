import { createFileRoute } from '@tanstack/react-router'
import { useCallback, useEffect, useState } from 'react'
import { z } from 'zod'
import { ErrorState } from '@/components/shared/error-state'
import { copy } from '@/copy/en'
import { platformDocsHandoff } from '@/features/platform'

const searchSchema = z.object({
  /** The page to open on the platform-docs host; the API accepts same-host paths only. */
  next: z.string().optional(),
})

/**
 * The console's way into the platform documentation (M5-01, ADR-0024 / M5-06). The header button opens
 * this page in a new tab, and the platform-docs host sends a visitor without a pass here too. It asks
 * the API for a one-time hand-off link and follows it; `_platform` has already required a signed-in
 * admin, and returns here after sign-in.
 */
export const Route = createFileRoute('/_platform/platform/docs')({
  validateSearch: searchSchema,
  component: PlatformDocsHandoff,
})

function PlatformDocsHandoff() {
  const { next } = Route.useSearch()
  const [error, setError] = useState<unknown>(null)

  const handOff = useCallback(async () => {
    setError(null)
    try {
      window.location.replace(await platformDocsHandoff(next ?? '/'))
    } catch (failure) {
      setError(failure)
    }
  }, [next])

  useEffect(() => {
    void handOff()
  }, [handOff])

  return error ? (
    <ErrorState error={error} title={copy.platform.docsFailed} onRetry={() => void handOff()} />
  ) : (
    <p role="status" className="text-muted-foreground text-sm">
      {copy.platform.openingDocs}
    </p>
  )
}

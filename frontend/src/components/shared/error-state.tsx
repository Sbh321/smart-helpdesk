import { RefreshCwIcon, TriangleAlertIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia } from '@/components/ui/empty'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { cn } from '@/lib/utils'

export interface ErrorStateProps {
  /** Usually an `ApiError`; anything else falls back to the generic wording. */
  error?: unknown
  title?: string
  onRetry?: () => void
  className?: string
}

/**
 * The failure state of a route or a composition (docs/06-design-system/components.md §ErrorState).
 * Only the problem detail written for humans is shown; raw server text never reaches the UI otherwise.
 */
export function ErrorState({ error, title, onRetry, className }: ErrorStateProps) {
  const apiError = isApiError(error) ? error : undefined
  const heading = title ?? apiError?.title ?? copy.states.error.title
  const description = apiError?.isNetwork
    ? copy.states.error.offline
    : (apiError?.detail ?? copy.states.error.body)

  return (
    <Empty className={cn('border border-border bg-surface', className)} role="alert">
      <EmptyHeader>
        <EmptyMedia variant="icon" className="bg-destructive/10 text-destructive">
          <TriangleAlertIcon aria-hidden="true" />
        </EmptyMedia>
        <h2 className="text-sm font-medium tracking-tight">{heading}</h2>
        <EmptyDescription>{description}</EmptyDescription>
      </EmptyHeader>
      <EmptyContent>
        {onRetry ? (
          <Button type="button" variant="outline" onClick={onRetry}>
            <RefreshCwIcon aria-hidden="true" />
            {copy.states.error.retry}
          </Button>
        ) : null}
        {apiError?.requestId ? (
          <p className="font-mono text-xs text-muted-foreground">
            {fill(copy.states.error.requestId, { id: apiError.requestId })}
          </p>
        ) : null}
      </EmptyContent>
    </Empty>
  )
}

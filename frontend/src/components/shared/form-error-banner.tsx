import { OctagonAlertIcon } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'

/**
 * A failed save that is not about one field (403, 409, 5xx, offline). Field messages of a 422 go to the
 * fields instead. `Alert` carries `role="alert"`, so the banner is announced when it appears.
 */
export function FormErrorBanner({ title, error }: { title: string; error: unknown }) {
  const apiError = isApiError(error) ? error : undefined
  const message = apiError?.isNetwork
    ? copy.states.error.offline
    : (apiError?.detail ?? copy.states.error.body)
  return (
    <Alert variant="destructive">
      <OctagonAlertIcon aria-hidden="true" />
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription>
        {message}
        {apiError?.requestId ? (
          <span className="block font-mono text-xs">
            {fill(copy.states.error.requestId, { id: apiError.requestId })}
          </span>
        ) : null}
      </AlertDescription>
    </Alert>
  )
}

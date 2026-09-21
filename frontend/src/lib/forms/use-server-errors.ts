import { useCallback, useState } from 'react'
import { isApiError } from '@/lib/api/errors'
import { serverFieldErrors } from './messages'

export interface ServerErrors {
  /** 422 messages by form field; array members fold onto their field (`tags.0` → `tags`). */
  fields: Record<string, string[]>
  /** The same messages under the API's exact keys (`targets.0.resolution_minutes`), for row editors. */
  exact: Record<string, string[]>
  /** Any failure that is not about a field: shown in a `FormErrorBanner`. */
  failure: unknown
  reset: () => void
  /** Call from the `catch` of `onSubmit`: sorts the error into `fields`/`exact` or `failure`. */
  capture: (error: unknown) => void
}

/** The server half of a form's messages (docs/03-architecture/frontend.md §Forms and mutations). */
export function useServerErrors(): ServerErrors {
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const [exact, setExact] = useState<Record<string, string[]>>({})
  const [failure, setFailure] = useState<unknown>(null)

  const reset = useCallback(() => {
    setFields({})
    setExact({})
    setFailure(null)
  }, [])

  const capture = useCallback((error: unknown) => {
    const folded = serverFieldErrors(error)
    if (Object.keys(folded).length > 0 && isApiError(error)) {
      setFields(folded)
      setExact(error.fieldErrors)
      setFailure(null)
    } else {
      setFields({})
      setExact({})
      setFailure(error)
    }
  }, [])

  return { fields, exact, failure, reset, capture }
}

import { isApiError } from '@/lib/api/errors'

/**
 * Validator output of TanStack Form is whatever the standard schema produced: an issue object with a
 * `message`, or a bare string. This flattens either into the strings a field renders.
 */
export function messagesOf(errors: readonly unknown[] | undefined): string[] {
  if (!errors) {
    return []
  }
  const messages: string[] = []
  for (const error of errors) {
    if (typeof error === 'string') {
      messages.push(error)
      continue
    }
    if (error && typeof error === 'object' && 'message' in error) {
      const { message } = error as { message: unknown }
      if (typeof message === 'string') {
        messages.push(message)
      }
    }
  }
  return messages
}

/** Client messages first, then the server's, without repeating the same text. */
export function mergeMessages(
  clientErrors: readonly unknown[] | undefined,
  serverErrors: readonly string[] | undefined,
): string[] {
  return [...new Set([...messagesOf(clientErrors), ...(serverErrors ?? [])])]
}

/**
 * Field messages of a 422 problem detail, keyed by the form's field names. Array members collapse onto
 * their field (`tags.0` → `tags`), because the form shows one message list per control. Any other
 * failure has no field messages.
 */
export function serverFieldErrors(error: unknown): Record<string, string[]> {
  if (!isApiError(error) || !error.isValidation) {
    return {}
  }
  const fields: Record<string, string[]> = {}
  for (const [key, messages] of Object.entries(error.fieldErrors)) {
    const field = key.split('.')[0] ?? key
    fields[field] = [...new Set([...(fields[field] ?? []), ...messages])]
  }
  return fields
}

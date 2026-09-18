import { copy, fill } from '@/copy/en'
import { type ApiError, isApiError } from '@/lib/api/errors'

/**
 * Turns an `ApiError` into a banner message. Codes we recognise get our own wording from `copy/en.ts`;
 * anything else falls back to the problem detail, which the API writes for humans
 * (docs/03-architecture/error-handling.md).
 */
export function authErrorMessage(error: unknown): string {
  if (!isApiError(error)) {
    return copy.auth.errors.unexpected
  }
  switch (error.code) {
    case 'invalid_credentials':
      return copy.auth.errors.invalidCredentials
    case 'tenant_suspended':
      return copy.auth.errors.tenantSuspended
    case 'account_locked':
      return lockedMessage(error)
    case 'rate_limited':
      return copy.auth.errors.rateLimited
    case 'network':
      return copy.states.error.offline
    default:
      return error.detail ?? copy.auth.errors.unexpected
  }
}

function lockedMessage(error: ApiError): string {
  const minutes = error.meta?.retry_after_minutes
  if (typeof minutes === 'number' && Number.isFinite(minutes) && minutes > 0) {
    return fill(copy.auth.errors.accountLocked, { minutes })
  }
  return copy.auth.errors.accountLockedNoMinutes
}

/** Field errors of a 422 problem detail, keyed by field name; empty for every other failure. */
export function fieldErrorsOf(error: unknown): Record<string, string[]> {
  return isApiError(error) && error.isValidation ? error.fieldErrors : {}
}

/** True when the failure was a 422, whose messages belong on the fields, not in the banner. */
export function isFieldError(error: unknown): boolean {
  return isApiError(error) && error.isValidation
}

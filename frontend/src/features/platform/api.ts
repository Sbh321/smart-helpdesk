import { type QueryClient, queryOptions } from '@tanstack/react-query'
import { readCookie } from '@/lib/api/client'
import { isApiError, toApiError, toNetworkError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'

/**
 * Platform API on the admin host (docs/07-api/authentication.md §6): same-origin `/platform-api/*`,
 * its own session cookie and its own CSRF pair (`XSRF-TOKEN-PLATFORM` echoed as `X-XSRF-TOKEN-PLATFORM`).
 * It is not in the tenant OpenAPI document, so these calls use `fetch` with hand-written types.
 *
 * MVP-SHORTCUT: sign-in, sign-out and a read-only tenant list only; V1: the full console (V1-PL-13).
 */

export const PLATFORM_XSRF_COOKIE = 'XSRF-TOKEN-PLATFORM'
export const PLATFORM_XSRF_HEADER = 'X-XSRF-TOKEN-PLATFORM'

export type PlatformUser = {
  id: string
  name: string
  email: string
  last_login_at: string | null
}

export type PlatformTenant = {
  id: string
  slug: string
  name: string
  status: string
  plan: string
  placement: string
  owner_email: string | null
  timezone: string
  suspended_at: string | null
  created_at: string | null
}

async function platformRequest<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    const token = readCookie(document.cookie, PLATFORM_XSRF_COOKIE)
    if (token) {
      headers[PLATFORM_XSRF_HEADER] = token
    }
  }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  let response: Response
  try {
    response = await globalThis.fetch(`/platform-api${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch (error) {
    throw toNetworkError(error)
  }
  const data: unknown = response.status === 204 ? undefined : await response.json().catch(() => undefined)
  if (!response.ok) {
    throw toApiError(response, data)
  }
  return data as T
}

/** Starts the platform session and sets the platform CSRF cookie; needed before the first unsafe call. */
async function ensurePlatformCsrfCookie(): Promise<void> {
  if (readCookie(document.cookie, PLATFORM_XSRF_COOKIE) === undefined) {
    await platformRequest<void>('GET', '/csrf-cookie')
  }
}

export async function platformLogin(input: { email: string; password: string }): Promise<void> {
  await ensurePlatformCsrfCookie()
  await platformRequest('POST', '/auth/login', input)
}

/** A one-time link that signs the admin in to the platform documentation host (ADR-0024, M5-06). */
export async function platformDocsHandoff(next: string): Promise<string> {
  await ensurePlatformCsrfCookie()
  const response = await platformRequest<{ data: { url: string } }>('POST', '/docs/handoff', { next })
  return response.data.url
}

export async function platformLogout(): Promise<void> {
  await ensurePlatformCsrfCookie()
  await platformRequest('POST', '/auth/logout')
}

/** `GET /platform-api/me`; a 401 or 419 means "nobody is signed in" and resolves to `null`. */
export const platformSessionQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.me(),
    queryFn: async (): Promise<PlatformUser | null> => {
      try {
        return (await platformRequest<{ data: PlatformUser }>('GET', '/me')).data
      } catch (error) {
        if (isApiError(error) && (error.status === 401 || error.status === 419)) {
          return null
        }
        throw error
      }
    },
    retry: false,
    staleTime: 30_000,
  })

export function ensurePlatformSession(queryClient: QueryClient): Promise<PlatformUser | null> {
  return queryClient.ensureQueryData(platformSessionQuery())
}

export function reloadPlatformSession(queryClient: QueryClient): Promise<PlatformUser | null> {
  return queryClient.fetchQuery({ ...platformSessionQuery(), staleTime: 0 })
}

export const platformTenantsQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.tenants(),
    queryFn: async () => (await platformRequest<{ data: PlatformTenant[] }>('GET', '/tenants')).data,
  })

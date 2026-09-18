import { type QueryClient, queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import type { MaybeSession } from '@/lib/auth'

/**
 * The session comes from `GET /v1/me` (docs/07-api/authentication.md). A 401 is not an error here: it
 * is the answer "nobody is signed in", so guards can branch on `null` instead of catching.
 */
export const sessionQuery = () =>
  queryOptions({
    queryKey: queryKeys.session.me(),
    queryFn: async (): Promise<MaybeSession> => {
      try {
        return await unwrap(api().GET('/me'))
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

/** Reads the session for a route guard, fetching once and reusing the cache afterwards. */
export function ensureSession(queryClient: QueryClient): Promise<MaybeSession> {
  return queryClient.ensureQueryData(sessionQuery())
}

/**
 * Reads the session again, ignoring `staleTime`; used after signing in, when the cached answer is the
 * 401 from before. It updates the existing query in place rather than removing it, because removing a
 * query leaves the mounted `useQuery` observer bound to the destroyed one and showing the old answer.
 */
export async function reloadSession(queryClient: QueryClient): Promise<MaybeSession> {
  return queryClient.fetchQuery({ ...sessionQuery(), staleTime: 0 })
}

/** Forgets the session without a request; used after `POST /v1/auth/logout`. */
export function clearSession(queryClient: QueryClient): void {
  queryClient.setQueryData(queryKeys.session.me(), null)
  queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'session' })
}

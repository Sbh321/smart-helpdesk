import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '@/lib/api/errors'

/** Retries up to twice, but never a 4xx: a 404 or 403 will not change, so the page shows it at once. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (
    error instanceof ApiError &&
    error.status >= 400 &&
    error.status < 500 &&
    error.status !== 408 &&
    error.status !== 429
  ) {
    return false
  }
  return failureCount < 2
}

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 15_000,
        retry: shouldRetry,
        refetchOnWindowFocus: true,
      },
      mutations: {
        retry: false,
      },
    },
  })
}

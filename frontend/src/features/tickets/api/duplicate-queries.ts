import { queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type DuplicateSuggestion = components['schemas']['DuplicateSuggestionResource']

/** The preview endpoint is throttled per user; the panel backs off silently. */
export function isRateLimited(error: unknown): boolean {
  return isApiError(error) && error.status === 429
}

export const duplicateQueries = {
  preview: (tenantId: string, title: string, description: string) =>
    queryOptions({
      queryKey: queryKeys.tickets.duplicatePreview(tenantId, title, description),
      queryFn: () => unwrap(api().POST('/tickets/preview-duplicates', { body: { title, description } })),
      staleTime: 60_000,
      // 429: the preview is advisory, so a throttled request is dropped instead of retried.
      retry: (failures, error) => !isRateLimited(error) && failures < 2,
    }),
  list: (tenantId: string, ticketId: string) =>
    queryOptions({
      queryKey: queryKeys.tickets.duplicates(tenantId, ticketId),
      queryFn: () =>
        unwrap(api().GET('/tickets/{ticket}/duplicates', { params: { path: { ticket: ticketId } } })),
    }),
}

export function dismissDuplicate(ticketId: string, candidateId: string): Promise<DuplicateSuggestion> {
  return unwrap(
    api().POST('/tickets/{ticket}/duplicates/{candidate}/dismiss', {
      params: { path: { ticket: ticketId, candidate: candidateId } },
    }),
  )
}

export function markDuplicate(ticketId: string, candidateId: string) {
  return unwrap(
    api().POST('/tickets/{ticket}/mark-duplicate', {
      params: { path: { ticket: ticketId } },
      body: { candidate_ticket_id: candidateId },
    }),
  )
}

import { infiniteQueryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type TicketComment = components['schemas']['TicketCommentResource']
export type CommentInput = components['schemas']['AddCommentRequest']

export const commentQueries = {
  list: (tenantId: string, ticketId: string) =>
    infiniteQueryOptions({
      queryKey: queryKeys.tickets.comments(tenantId, ticketId),
      initialPageParam: 1,
      queryFn: ({ pageParam }) =>
        unwrapBody(
          api().GET('/tickets/{ticket}/comments', {
            params: { path: { ticket: ticketId }, query: { page: pageParam } },
          }),
        ),
      getNextPageParam: (lastPage) =>
        lastPage.meta.current_page < lastPage.meta.last_page ? lastPage.meta.current_page + 1 : undefined,
    }),
}

export const addComment = (ticketId: string, input: CommentInput) =>
  unwrap(api().POST('/tickets/{ticket}/comments', { params: { path: { ticket: ticketId } }, body: input }))

import { queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type AssignmentPreview = components['schemas']['AssignmentPreviewResource']

export const assignmentQueries = {
  candidates: (tenantId: string, ticketId: string) =>
    queryOptions({
      queryKey: queryKeys.assignment.candidates(tenantId, ticketId),
      queryFn: () =>
        unwrap(
          api().GET('/tickets/{ticket}/assignment-candidates', { params: { path: { ticket: ticketId } } }),
        ),
    }),
}

export const assignTicket = (ticketId: string, agentId: string) =>
  unwrap(
    api().POST('/tickets/{ticket}/assign', {
      params: { path: { ticket: ticketId } },
      body: { agent_id: agentId },
    }),
  )
export const autoAssignTicket = (ticketId: string) =>
  unwrap(api().POST('/tickets/{ticket}/auto-assign', { params: { path: { ticket: ticketId } } }))
export const unassignTicket = (ticketId: string) =>
  unwrap(api().POST('/tickets/{ticket}/unassign', { params: { path: { ticket: ticketId } } }))

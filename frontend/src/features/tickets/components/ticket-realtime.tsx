import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { COMMENT_ADDED, RealtimeSubscription, realtimeChannels, TICKET_EVENTS } from '@/lib/realtime'

const COMMENT_EVENTS = [COMMENT_ADDED] as const

/**
 * Live updates for the ticket list (M3-16): any ticket event of the workspace refetches the list
 * pages; the 30 s poll stays as the fallback.
 */
export function TicketListRealtime({ tenantId }: { tenantId: string }) {
  return (
    <RealtimeSubscription
      channel={tenantId === '' ? '' : realtimeChannels.tickets(tenantId)}
      events={TICKET_EVENTS}
      invalidate={[[...queryKeys.tickets.all(tenantId), 'list']]}
    />
  )
}

/**
 * Live updates for an open ticket: its own changes, public replies and, for members with
 * `comments.internal`, internal notes (a separate channel the server authorises for that permission).
 */
export function TicketDetailRealtime({ tenantId, ticketId }: { tenantId: string; ticketId: string }) {
  const canInternal = useCan('comments.internal')
  if (tenantId === '') {
    return null
  }
  const ticket = [
    queryKeys.tickets.detail(tenantId, ticketId),
    queryKeys.tickets.history(tenantId, ticketId),
    queryKeys.sla.ticket(tenantId, ticketId),
  ]
  const comments = [
    queryKeys.tickets.comments(tenantId, ticketId),
    queryKeys.tickets.history(tenantId, ticketId),
    queryKeys.tickets.detail(tenantId, ticketId),
  ]

  return (
    <>
      <RealtimeSubscription
        channel={realtimeChannels.ticket(tenantId, ticketId)}
        events={TICKET_EVENTS}
        invalidate={ticket}
      />
      <RealtimeSubscription
        channel={realtimeChannels.ticket(tenantId, ticketId)}
        events={COMMENT_EVENTS}
        invalidate={comments}
      />
      {canInternal ? (
        <RealtimeSubscription
          channel={realtimeChannels.ticketInternal(tenantId, ticketId)}
          events={COMMENT_EVENTS}
          invalidate={comments}
        />
      ) : null}
    </>
  )
}

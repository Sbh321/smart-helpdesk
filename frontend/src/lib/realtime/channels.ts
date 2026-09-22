/**
 * Private channel names and broadcast event names (docs/03-architecture/realtime.md §Channels). They
 * mirror `Realtime\Support\Channels` on the server, which refuses a channel whose tenant is not the
 * session's. Event names start with a dot because they are `broadcastAs()` names, not class names.
 */
export const realtimeChannels = {
  tickets: (tenantId: string) => `tenants.${tenantId}.tickets`,
  ticket: (tenantId: string, ticketId: string) => `tenants.${tenantId}.tickets.${ticketId}`,
  /** Internal notes only; the server authorises it for `comments.internal`. */
  ticketInternal: (tenantId: string, ticketId: string) => `tenants.${tenantId}.tickets.${ticketId}.internal`,
  user: (tenantId: string, userId: string) => `tenants.${tenantId}.users.${userId}`,
} as const

export const TICKET_EVENTS = [
  '.ticket.created',
  '.ticket.updated',
  '.ticket.assigned',
  '.ticket.status_changed',
  '.ticket.priority_changed',
] as const

export const COMMENT_ADDED = '.comment.added'

export const NOTIFICATION_CREATED = '.notification.created'

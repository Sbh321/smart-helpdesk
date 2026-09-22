import type { TicketEvent } from './api/ticket-queries'

/** Consecutive events by the same actor within this window read as one step (a create with its assignment). */
export const GROUP_WINDOW_MS = 5 * 60 * 1000

export interface TicketEventGroup {
  key: string
  actorType: string
  actorId: string | null
  /** Newest first, as the feed delivers them. */
  events: TicketEvent[]
}

/**
 * Groups a newest-first event feed: an event joins the group above it when the actor is the same and it
 * happened within `windowMs` of the group's newest event. Order is kept; nothing is merged across actors.
 */
export function groupTicketEvents(
  events: readonly TicketEvent[],
  windowMs = GROUP_WINDOW_MS,
): TicketEventGroup[] {
  const groups: TicketEventGroup[] = []
  for (const event of events) {
    const current = groups.at(-1)
    const newest = current?.events[0]
    if (
      current &&
      newest &&
      current.actorType === event.actor_type &&
      current.actorId === event.actor_id &&
      Date.parse(newest.created_at) - Date.parse(event.created_at) <= windowMs
    ) {
      current.events.push(event)
    } else {
      groups.push({ key: event.id, actorType: event.actor_type, actorId: event.actor_id, events: [event] })
    }
  }
  return groups
}

/** The icon family of an event type, for the timeline (`ticket_events_type_check`). */
export type TicketEventKind =
  | 'created'
  | 'status'
  | 'priority'
  | 'assignment'
  | 'comment'
  | 'attachment'
  | 'sla'
  | 'duplicate'
  | 'edit'

export function ticketEventKind(type: string): TicketEventKind {
  if (type === 'created') return 'created'
  if (type === 'status_changed' || type === 'reopened') return 'status'
  if (type.startsWith('priority_') || type === 'escalated') return 'priority'
  if (type === 'assigned' || type === 'unassigned') return 'assignment'
  if (type === 'comment_added') return 'comment'
  if (type === 'attachment_added') return 'attachment'
  if (type.startsWith('sla_')) return 'sla'
  if (type.startsWith('duplicate_')) return 'duplicate'
  return 'edit'
}

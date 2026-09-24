import { copy, fill } from '@/copy/en'
import { hasPermission } from '@/lib/auth'
import { formatRecordValue, type NameLookup, shortId } from '@/lib/format/record-values'
import type { OverviewEntity } from './api/entity-queries'

const text = copy.entity360

/** The permission that opens the overview of each entity (the overview routes' `can:` middleware). */
export const OVERVIEW_PERMISSION: Record<OverviewEntity, string> = {
  tickets: 'tickets.view',
  contacts: 'contacts.view',
  organizations: 'contacts.view',
  agents: 'agents.view',
  teams: 'agents.view',
  categories: 'tickets.view',
}

/**
 * Whether the history API answers for this entity (docs/03-architecture/security.md §History as built):
 * the route needs `tickets.view`; the subject its own view permission; then `history.view`, or for the
 * ticket family `comments.internal` (agents: "tickets only").
 */
export function canViewHistory(entity: OverviewEntity, permissions: readonly string[]): boolean {
  const can = (permission: string) => hasPermission(permissions, permission)
  if (!can('tickets.view') || !can(OVERVIEW_PERMISSION[entity])) return false
  return can('history.view') || (entity === 'tickets' && can('comments.internal'))
}

// The value vocabulary is shared with the ticket timeline and the audit log (lib/format/record-values).
export { attributeLabel, type NameLookup, shortId } from '@/lib/format/record-values'

/**
 * A recorded value as text, in the one-line form a timeline needs (a nested value reads "3 values").
 * Kept as a named export so the history components read the same way as before M4-03.
 */
export function formatAttributeValue(
  attribute: string,
  value: unknown,
  timeZone: string,
  names: NameLookup = {},
): string {
  return formatRecordValue(value, timeZone, { attribute, names, structured: 'count' })
}

/** Who made a change, in words. */
export function actorLabel(
  actorType: string | null,
  actorId: string | null,
  currentUserId: string | undefined,
  names: NameLookup = {},
): string {
  switch (actorType) {
    case 'user': {
      if (!actorId) return text.actors.unknown
      if (actorId === currentUserId) return text.actors.you
      const name = names.user?.(actorId)
      return name ?? fill(text.actors.user, { id: shortId(actorId) })
    }
    case 'api_client':
      return fill(text.actors.api_client, { id: actorId ? shortId(actorId) : '' }).trim()
    case 'system':
      return text.actors.system
    case 'email':
      return text.actors.email
    case 'platform':
      return text.actors.platform
    default:
      return text.actors.unknown
  }
}

/** One interval of the ticket lifecycle trace (`trends.lifecycle`). */
export interface LifecycleInterval {
  seq: number
  status: string
  assigned_agent_id: string | null
  team_id: string | null
  priority_level: string | null
  starts_at: string
  ends_at: string | null
  wall_seconds: number
  business_seconds: number | null
  open: boolean
}

/** The share of the whole trace each interval takes, in per cent (all zero when nothing has elapsed). */
export function lifecycleShares(intervals: readonly LifecycleInterval[]): number[] {
  const total = intervals.reduce((sum, interval) => sum + Math.max(0, interval.wall_seconds), 0)
  return intervals.map((interval) => (total === 0 ? 0 : (Math.max(0, interval.wall_seconds) / total) * 100))
}

import { copy, fill } from '@/copy/en'
import { hasPermission } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import type { OverviewEntity } from './api/entity-queries'
import { formatDuration } from './format'

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

/** "priority_override_reason" → "Priority override reason", for a column without a known name. */
export function attributeLabel(attribute: string): string {
  const known = text.attributes[attribute]
  if (known) return known
  const words = attribute.replace(/_id$/, '').replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** Names of the records the ids of a change refer to, from option queries already in the cache. */
export interface NameLookup {
  organization?: (id: string) => string | undefined
  category?: (id: string) => string | undefined
  team?: (id: string) => string | undefined
  agent?: (id: string) => string | undefined
  /** By user id: the Agent directory knows the names of users who are Agents. */
  user?: (id: string) => string | undefined
}

const RELATIONS: Record<string, keyof NameLookup> = {
  organization_id: 'organization',
  category_id: 'category',
  team_id: 'team',
  default_team_id: 'team',
  assigned_agent_id: 'agent',
  user_id: 'user',
  created_by_user_id: 'user',
  priority_override_by: 'user',
}

const LABELLED: Record<string, Record<string, string>> = {
  status: copy.reports.fixedLabels.status ?? {},
  priority_level: copy.reports.fixedLabels.priority ?? {},
  priority_override_level: copy.reports.fixedLabels.priority ?? {},
  tier: copy.reports.fixedLabels.tier ?? {},
  created_via: copy.reports.fixedLabels.channel ?? {},
  availability: {
    available: copy.settings.availabilityControl.available,
    away: copy.settings.availabilityControl.away,
    offline: copy.settings.availabilityControl.offline,
  },
}

const ISO_INSTANT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** The last eight characters of an id, for a record we have no name for ("…a1b2c3d4"). */
export function shortId(id: string): string {
  return fill(text.values.shortId, { id: id.slice(-8) })
}

/**
 * A recorded value as text: names for known relations, labels for enumerations, instants in the
 * workspace zone, durations for second counts, a short id for an unknown record, a count for
 * structured values. Never a whole UUID unless there is nothing better.
 */
export function formatAttributeValue(
  attribute: string,
  value: unknown,
  timeZone: string,
  names: NameLookup = {},
): string {
  if (value === null || value === undefined || value === '') return text.values.empty
  if (typeof value === 'boolean') return value ? text.values.yes : text.values.no
  if (typeof value === 'object') {
    const size = Array.isArray(value) ? value.length : Object.keys(value).length
    return size === 0 ? text.values.empty : fill(text.values.structured, { count: size })
  }
  if (typeof value === 'number') {
    if (attribute.endsWith('_seconds')) return formatDuration(value)
    return String(value)
  }
  const raw = String(value)
  const relation = RELATIONS[attribute]
  if (relation && UUID.test(raw)) return names[relation]?.(raw) ?? shortId(raw)
  const labels = LABELLED[attribute]
  if (labels?.[raw]) return labels[raw]
  if (ISO_INSTANT.test(raw)) return formatInZone(raw, timeZone, 'd MMM yyyy, HH:mm:ss')
  if (UUID.test(raw)) return shortId(raw)
  return raw
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

import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { formatDuration } from '@/lib/format/measure'

/**
 * One way to turn a stored value into something a person reads (roadmap M4-03). Three surfaces record
 * what changed — the ticket timeline, the entity History tab and the audit log — and each had its own
 * idea of how a value looks, so the ticket timeline still printed
 * `team_id: null → 01a0c549-1f64-71de-933d-ef235eb3edd1` (docs/06-design-system/ux-review.md G4).
 *
 * The rules, in order: nothing becomes "—", booleans become Yes/No, a list joins its items, a nested
 * object reads `key: value; key: value`, a `*_seconds` number becomes a duration, a known relation id
 * becomes the record's name, a known enumeration becomes its label, an instant is shown in the
 * workspace zone, and only an id we cannot name falls back to its last eight characters.
 */
const text = copy.entity360

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
  agent_id: 'agent',
  user_id: 'user',
  created_by_user_id: 'user',
  priority_override_by: 'user',
  author_user_id: 'user',
}

const LABELLED: Record<string, Record<string, string>> = {
  status: copy.reports.fixedLabels.status ?? {},
  priority_level: copy.reports.fixedLabels.priority ?? {},
  priority_override_level: copy.reports.fixedLabels.priority ?? {},
  tier: copy.reports.fixedLabels.tier ?? {},
  created_via: copy.reports.fixedLabels.channel ?? {},
  channel: copy.reports.fixedLabels.channel ?? {},
  visibility: {
    public: copy.comments.public,
    internal: copy.comments.internal,
  },
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

/** "priority_override_reason" → "Priority override reason", for a column without a known name. */
export function attributeLabel(attribute: string): string {
  const known = text.attributes[attribute]
  if (known) return known
  const words = attribute.replace(/_id$/, '').replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export interface ValueOptions {
  /** The column the value belongs to; decides relation names, enumeration labels and durations. */
  attribute?: string
  names?: NameLookup
  /**
   * How a list or nested object reads: `join` spells the items out (audit log, where the detail is the
   * point), `count` says how many there are (history timelines, where the row must stay one line).
   */
  structured?: 'join' | 'count'
}

/** A recorded value as text. Never a whole UUID unless there is nothing better. */
export function formatRecordValue(value: unknown, timeZone: string, options: ValueOptions = {}): string {
  const { attribute = '', names = {}, structured = 'count' } = options

  if (value === null || value === undefined || value === '') return text.values.empty
  if (typeof value === 'boolean') return value ? text.values.yes : text.values.no

  if (Array.isArray(value)) {
    if (value.length === 0) return text.values.empty
    return structured === 'join'
      ? value.map((item) => formatRecordValue(item, timeZone, options)).join(', ')
      : fill(text.values.structured, { count: value.length })
  }

  if (isRecord(value)) {
    const entries = Object.entries(value)
    if (entries.length === 0) return text.values.empty
    return structured === 'join'
      ? entries
          .map(([key, item]) => `${attributeLabel(key)}: ${formatRecordValue(item, timeZone, options)}`)
          .join('; ')
      : fill(text.values.structured, { count: entries.length })
  }

  if (typeof value === 'number') {
    return attribute.endsWith('_seconds') ? formatDuration(value) : String(value)
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

/** One line of a change: the column in words, and the values before and after it. */
export interface ReadableChange {
  label: string
  from: string | null
  to: string
}

/**
 * The lines a `{attribute: {old, new}}` or `{attribute: value}` map produces, ready to render. `from`
 * is null when the change records no previous value (a creation).
 */
export function readableChanges(
  changes: Record<string, unknown>,
  timeZone: string,
  options: Omit<ValueOptions, 'attribute'> = {},
): ReadableChange[] {
  return Object.entries(changes).map(([attribute, value]) => {
    const pair = isRecord(value) && ('old' in value || 'new' in value) ? value : null
    const format = (item: unknown) => formatRecordValue(item, timeZone, { ...options, attribute })
    return {
      label: attributeLabel(attribute),
      from: pair ? format(pair.old) : null,
      to: format(pair ? pair.new : value),
    }
  })
}

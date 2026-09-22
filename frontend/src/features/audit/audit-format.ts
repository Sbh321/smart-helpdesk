import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import type { AuditEntry } from './api/audit-queries'

const text = copy.audit

/** The last eight characters of an id, for a record without a name ("…a1b2c3d4"). */
export function shortId(id: string): string {
  return `…${id.slice(-8)}`
}

function humanise(words: string): string {
  const spaced = words.replace(/_/g, ' ')
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/** "user.invited" → "User invited"; an action we have no words for reads "Mail sender · verified". */
export function actionLabel(action: string): string {
  const known = text.actions[action]
  if (known) return known
  const [subject = action, verb] = action.split('.')
  return verb ? `${humanise(subject)} · ${verb.replace(/_/g, ' ')}` : humanise(subject)
}

export function subjectTypeLabel(type: string): string {
  return text.subjectTypes[type] ?? humanise(type)
}

/** Who did it: "You", the user's or API client's name, or the kind of actor with a short id. */
export function auditActorLabel(entry: AuditEntry, currentUserId: string | undefined): string {
  if (entry.actor_type === 'user' && entry.actor_id !== null && entry.actor_id === currentUserId) {
    return text.actors.you ?? 'You'
  }
  if (entry.actor_name) return entry.actor_name
  const template = text.actors[entry.actor_type] ?? '{id}'
  return fill(template, { id: entry.actor_id ? shortId(entry.actor_id) : '' }).trim()
}

/** Which record: its name when it still exists, otherwise its type and a short id. */
export function auditSubjectLabel(entry: AuditEntry): string | null {
  if (entry.subject_type === null) return null
  const type = subjectTypeLabel(entry.subject_type)
  if (entry.subject_name) return entry.subject_name
  return entry.subject_id ? `${type} ${shortId(entry.subject_id)}` : type
}

/** One line of the expandable changes: a field with old → new, or a recorded value. */
export interface AuditChange {
  field: string
  kind: 'change' | 'value'
  old?: unknown
  new?: unknown
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isOldNew(value: unknown): value is { old?: unknown; new?: unknown } {
  if (!isRecord(value)) return false
  const keys = Object.keys(value)
  return keys.length > 0 && keys.every((key) => key === 'old' || key === 'new')
}

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

/** Old and new of a pair: per field when both are objects, else one "value" line. */
function pairChanges(before: unknown, after: unknown): AuditChange[] {
  if (isRecord(before) && isRecord(after)) {
    return [...new Set([...Object.keys(before), ...Object.keys(after)])]
      .filter((field) => !same(before[field], after[field]))
      .map((field) => ({ field, kind: 'change', old: before[field], new: after[field] }))
  }
  return [{ field: '', kind: 'change', old: before, new: after }]
}

/**
 * The recorded `changes` of an entry as lines (docs/04-domain/audit.md §As built (M3-03)). Producers use
 * three shapes: `{field: {old, new}}`; `{old: …, new: …}` or `{before: …, after: …}` with context beside
 * them (a settings section, a version); and free-form context (`{email, roles}`), shown as values.
 */
export function auditChanges(changes: Record<string, unknown>): AuditChange[] {
  const lines: AuditChange[] = []
  const pair =
    'old' in changes || 'new' in changes
      ? ['old', 'new']
      : 'before' in changes || 'after' in changes
        ? ['before', 'after']
        : null
  if (pair) {
    const [from = 'old', to = 'new'] = pair
    lines.push(...pairChanges(changes[from], changes[to]))
  }
  for (const [field, value] of Object.entries(changes)) {
    if (pair?.includes(field)) continue
    lines.push(
      isOldNew(value)
        ? { field, kind: 'change', old: value.old, new: value.new }
        : { field, kind: 'value', new: value },
    )
  }
  return lines
}

const ISO_INSTANT = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** A recorded value as text: lists joined, instants in the workspace zone, ids shortened. */
export function auditValue(value: unknown, timeZone: string): string {
  if (value === null || value === undefined || value === '') return text.changes.empty
  if (typeof value === 'boolean') return value ? copy.entity360.values.yes : copy.entity360.values.no
  if (Array.isArray(value)) {
    return value.length === 0
      ? text.changes.empty
      : value.map((item) => auditValue(item, timeZone)).join(', ')
  }
  if (isRecord(value)) {
    const entries = Object.entries(value)
    return entries.length === 0
      ? text.changes.empty
      : entries.map(([key, item]) => `${humanise(key)}: ${auditValue(item, timeZone)}`).join('; ')
  }
  const raw = String(value)
  if (ISO_INSTANT.test(raw)) return formatInZone(raw, timeZone, 'd MMM yyyy, HH:mm:ss')
  if (UUID.test(raw)) return shortId(raw)
  return raw
}

export function fieldLabel(field: string): string {
  return field === '' ? text.changes.value : humanise(field.replace(/_id$/, ''))
}

/** The Entity 360 history type → the audit subject type of the same record, where one exists. */
export const AUDIT_SUBJECT_TYPE: Record<string, string> = {
  tickets: 'ticket',
  contacts: 'contact',
  organizations: 'organization',
  teams: 'team',
  agents: 'agent_profile',
  categories: 'category',
}

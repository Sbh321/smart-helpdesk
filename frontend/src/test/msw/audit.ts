import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { fixtureId, SESSION_USER_ID } from './data'
import { apiUrl } from './handlers'
import { validationFailed } from './list'

/**
 * `GET /v1/audit-logs` (roadmap M3-03), shaped as the backend's `AuditLogResource`: filters as in
 * `IndexAuditLogsRequest`, newest first, a cursor that is the offset of the next page. Dates of
 * `filter[created_between]` are taken as UTC days here (the workspace zone is the backend's concern).
 */
type AuditEntry = components['schemas']['AuditLogResource']

export const AUDIT_ASHA_ID = fixtureId(50, 2)
export const AUDIT_ROLE_ID = fixtureId(60, 1)
export const AUDIT_WEBHOOK_ID = fixtureId(60, 2)
export const AUDIT_TICKET_ID = fixtureId(3, 1)

let sequence = 0
function entry(action: string, createdAt: string, fields: Partial<AuditEntry> = {}): AuditEntry {
  sequence += 1
  return {
    id: `019a0aaa-0000-7000-8000-${sequence.toString(16).padStart(12, '0')}`,
    action,
    actor_type: 'user',
    actor_id: SESSION_USER_ID,
    actor_name: 'Priya Sharma',
    subject_type: null,
    subject_id: null,
    subject_name: null,
    changes: {},
    ip_address: '203.0.113.7',
    user_agent: 'Mozilla/5.0',
    request_id: 'req-audit-1',
    created_at: createdAt,
    ...fields,
  }
}

/** Oldest last. Thirty sign-ins at the end make a second page. */
function fixtures(): AuditEntry[] {
  return [
    entry('role.permissions_changed', '2026-09-20T10:00:00.000000Z', {
      subject_type: 'role',
      subject_id: AUDIT_ROLE_ID,
      subject_name: 'billing-lead',
      changes: { before: ['tickets.view'], after: ['tickets.view', 'tickets.update'] },
    }),
    entry('user.role_changed', '2026-09-20T09:00:00.000000Z', {
      actor_id: fixtureId(50, 3),
      actor_name: 'Bikram Shah',
      subject_type: 'user',
      subject_id: AUDIT_ASHA_ID,
      subject_name: 'Asha Rai',
      changes: { old: { roles: ['agent'] }, new: { roles: ['agent', 'billing-lead'] } },
    }),
    entry('webhook.disabled', '2026-09-19T08:00:00.000000Z', {
      actor_type: 'system',
      actor_id: null,
      actor_name: null,
      subject_type: 'webhook_subscription',
      subject_id: AUDIT_WEBHOOK_ID,
      subject_name: 'Old chat bot',
      changes: { reason: 'consecutive_failures', consecutive_failures: 20 },
      ip_address: null,
      user_agent: null,
      request_id: null,
    }),
    entry('ticket.priority_overridden', '2026-09-18T12:00:00.000000Z', {
      subject_type: 'ticket',
      subject_id: AUDIT_TICKET_ID,
      subject_name: '#101',
      changes: { level: { old: 'P3', new: 'P1' }, reason: 'VIP outage' },
    }),
    entry('api_client.created', '2026-09-18T11:00:00.000000Z', {
      actor_type: 'api_client',
      actor_id: fixtureId(61, 1),
      actor_name: 'Monitoring',
      subject_type: 'api_client',
      subject_id: fixtureId(61, 2),
      subject_name: null,
      changes: { name: 'Status page', scopes: ['tickets:read'] },
    }),
    ...Array.from({ length: 30 }, (_, index) =>
      entry('user.logged_in', `2026-09-10T${String(index % 24).padStart(2, '0')}:00:00.000000Z`, {
        subject_type: 'user',
        subject_id: SESSION_USER_ID,
        subject_name: 'Priya Sharma',
      }),
    ).reverse(),
  ]
}

export const auditDb = { entries: fixtures(), requests: [] as URL[] }

export function resetAudit(): void {
  sequence = 0
  auditDb.entries = fixtures()
  auditDb.requests = []
}

const FILTERS = ['action', 'actor_type', 'actor_id', 'subject_type', 'subject_id', 'created_between']

function values(url: URL, key: string): string[] {
  return (url.searchParams.get(`filter[${key}]`) ?? '').split(',').filter((value) => value !== '')
}

export const auditHandlers = [
  http.get(apiUrl('/audit-logs'), ({ request }) => {
    const url = new URL(request.url)
    auditDb.requests.push(url)
    for (const key of url.searchParams.keys()) {
      const filter = /^filter\[(.+)\]$/.exec(key)?.[1]
      if (['page', 'sort', 'search'].includes(key) || (filter !== undefined && !FILTERS.includes(filter)))
        return validationFailed({ [filter ? `filter.${filter}` : key]: ['Not supported.'] })
    }
    let rows = [...auditDb.entries]
    const actions = values(url, 'action')
    if (actions.length > 0)
      rows = rows.filter((row) =>
        actions.some((action) =>
          action.endsWith('.*') ? row.action.startsWith(action.slice(0, -1)) : row.action === action,
        ),
      )
    for (const key of ['actor_type', 'actor_id', 'subject_type', 'subject_id'] as const) {
      const wanted = values(url, key)
      if (wanted.length > 0) rows = rows.filter((row) => wanted.includes(String(row[key])))
    }
    const [from, to] = values(url, 'created_between')
    if (from && to)
      rows = rows.filter((row) => row.created_at.slice(0, 10) >= from && row.created_at.slice(0, 10) <= to)
    rows.sort((a, b) => b.created_at.localeCompare(a.created_at) || b.id.localeCompare(a.id))

    const perPage = Number(url.searchParams.get('per_page') ?? 25)
    const offset = Number(url.searchParams.get('cursor') ?? 0)
    const next = offset + perPage < rows.length ? String(offset + perPage) : null
    return HttpResponse.json({
      data: rows.slice(offset, offset + perPage),
      links: { first: null, last: null, prev: null, next: null },
      meta: { path: null, per_page: perPage, next_cursor: next, prev_cursor: null },
    })
  }),
]

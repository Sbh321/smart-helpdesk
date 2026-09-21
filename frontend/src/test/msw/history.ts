import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { AGENT_FIXTURES, db, fixtureId, ORGANIZATION_FIXTURES, SESSION_USER_ID } from './data'
import { apiUrl, problem } from './handlers'
import { validationFailed } from './list'

/**
 * Entity 360 (M3-21): overviews, change logs and as-of views, shaped as the backend's
 * `Overviews\EntityOverviews`, `EntityChangeResource` and `AsOfResource`. The as-of view is the real
 * backward replay (docs/05-algorithms/history-and-time-analytics.md §3) over the fixture changes.
 */
type EntityChange = components['schemas']['EntityChangeResource']
type Overview = components['schemas']['EntityOverviewResource']
type Attributes = Record<string, unknown>

const PAGE = 50
const notFound = () => problem(404, 'not_found', { title: 'Not found' })

/** Contact 1 moved Globex → Initech → Acme Corporation; its current organisation is Acme. */
export const HISTORY_CONTACT_ID = fixtureId(1, 1)
/** Organisation 1 has 60 recorded changes: more than one page of history. */
export const HISTORY_ORGANIZATION_ID = fixtureId(2, 1)
export const HISTORY_CONTACT_CREATED_AT = '2026-08-01T04:00:00.000000Z'
export const HISTORY_FIRST_MOVE_AT = '2026-09-10T04:15:00.000000Z'
export const HISTORY_SECOND_MOVE_AT = '2026-09-15T06:30:00.000000Z'

const [ACME, GLOBEX, INITECH] = ORGANIZATION_FIXTURES.map((organization) => organization.id)

let sequence = 0
function change(
  operation: EntityChange['operation'],
  version: number,
  changes: EntityChange['changes'],
  occurredAt: string,
  actor: Pick<EntityChange, 'actor_type' | 'actor_id'> = { actor_type: 'user', actor_id: SESSION_USER_ID },
): EntityChange {
  sequence += 1
  return {
    id: `019a00ff-0000-7000-8000-${sequence.toString(16).padStart(12, '0')}`,
    version,
    operation,
    changes,
    ...actor,
    occurred_at: occurredAt,
  }
}

interface Record360 {
  /** The row as the capture trigger sees it now; `null` once deleted. */
  current: Attributes | null
  /** Oldest first. */
  changes: EntityChange[]
}

function contactHistory(): Record360 {
  const current: Attributes = {
    id: HISTORY_CONTACT_ID,
    name: 'Aarav Adhikari',
    email: 'aarav.adhikari.1@example.test',
    phone: '+977-9800000000',
    organization_id: ACME,
    last_ticket_at: '2026-09-18T10:00:00+00:00',
    archived_at: null,
    created_at: '2026-08-01T04:00:00+00:00',
  }
  return {
    current,
    changes: [
      change(
        'insert',
        1,
        {
          name: { old: null, new: 'Aarav Adhikari' },
          email: { old: null, new: 'aarav.adhikari.1@example.test' },
          phone: { old: null, new: '+977-9800000000' },
          organization_id: { old: null, new: GLOBEX },
          last_ticket_at: { old: null, new: null },
          created_at: { old: null, new: '2026-08-01T04:00:00+00:00' },
        },
        HISTORY_CONTACT_CREATED_AT,
        { actor_type: 'api_client', actor_id: '01a0c293-4c76-7163-afa0-f50fe59dee00' },
      ),
      change('update', 2, { organization_id: { old: GLOBEX, new: INITECH } }, HISTORY_FIRST_MOVE_AT),
      change('update', 3, { organization_id: { old: INITECH, new: ACME } }, HISTORY_SECOND_MOVE_AT, {
        actor_type: 'user',
        actor_id: AGENT_FIXTURES[0]?.user.id ?? null,
      }),
      change(
        'update',
        4,
        { last_ticket_at: { old: null, new: '2026-09-18T10:00:00+00:00' } },
        '2026-09-18T10:00:00.000000Z',
        { actor_type: 'system', actor_id: null },
      ),
    ],
  }
}

function organizationHistory(): Record360 {
  const tiers = ['standard', 'premium', 'enterprise'] as const
  const changes: EntityChange[] = [
    change(
      'insert',
      1,
      { name: { old: null, new: 'Acme Corporation' }, tier: { old: null, new: 'standard' } },
      '2026-07-01T00:00:00.000000Z',
    ),
  ]
  for (let index = 1; index < 60; index += 1) {
    const at = new Date(Date.parse('2026-07-02T00:00:00Z') + index * 3_600_000).toISOString()
    changes.push(
      change(
        'update',
        index + 1,
        { tier: { old: tiers[(index - 1) % 3], new: tiers[index % 3] } },
        at.replace('.000Z', '.000000Z'),
      ),
    )
  }
  return {
    current: {
      id: HISTORY_ORGANIZATION_ID,
      name: 'Acme Corporation',
      tier: tiers[59 % 3],
      domain: 'acme.example',
    },
    changes,
  }
}

let histories: Record<string, Record360> = {}

/** Restores the fixture histories; called between tests with the other mock data. */
export function resetHistory(): void {
  sequence = 0
  histories = {
    [`contacts:${HISTORY_CONTACT_ID}`]: contactHistory(),
    [`organizations:${HISTORY_ORGANIZATION_ID}`]: organizationHistory(),
  }
}
resetHistory()

/** A record without fixture history: its current row from the mock data, created at its creation time. */
function fallback(type: string, id: string): Record360 | undefined {
  const row: Attributes | undefined =
    type === 'contacts'
      ? db.contacts.find((contact) => contact.id === id)
      : type === 'organizations'
        ? db.organizations.find((organization) => organization.id === id)
        : type === 'tickets'
          ? db.tickets.find((ticket) => ticket.id === id)
          : type === 'agent_profiles'
            ? db.agents.find((agent) => agent.id === id)
            : type === 'teams'
              ? db.teams.find((team) => team.id === id)
              : type === 'categories'
                ? db.categories.find((category) => category.id === id)
                : undefined
  if (!row) return undefined
  const current: Attributes = { id }
  for (const [key, value] of Object.entries(row)) {
    if (value === null || ['string', 'number', 'boolean'].includes(typeof value)) current[key] = value
  }
  return { current, changes: [] }
}

function history(type: string, id: string): Record360 | undefined {
  return histories[`${type}:${id}`] ?? fallback(type, id)
}

/** Backward replay: undo, newest first, every change after `at`. */
function asOf(record: Record360, at: number): Attributes | null {
  const state: Attributes = record.current ? { ...record.current } : {}
  for (const entry of [...record.changes].reverse()) {
    if (Date.parse(entry.occurred_at) <= at) break
    if (entry.operation === 'insert') return null
    for (const [attribute, values] of Object.entries(entry.changes)) state[attribute] = values.old
  }
  return state
}

const KNOWN_TYPES = new Set(['tickets', 'contacts', 'organizations', 'agent_profiles', 'teams', 'categories'])

function weeks(seed: number): Array<{ week: string; tickets: number }> {
  return Array.from({ length: 12 }, (_, index) => {
    const day = new Date(Date.parse('2026-06-29T00:00:00Z') + index * 7 * 86_400_000)
    return { week: day.toISOString().slice(0, 10), tickets: (seed * (index + 3)) % 9 }
  })
}

function days(seed: number): Array<{ day: string; backlog: number }> {
  return Array.from({ length: 30 }, (_, index) => {
    const day = new Date(Date.parse('2026-08-21T00:00:00Z') + index * 86_400_000)
    return { day: day.toISOString().slice(0, 10), backlog: 4 + ((seed + index) % 6) }
  })
}

const TICKET_METRICS = {
  tickets: 12,
  open_tickets: 3,
  reopen_rate: 8.3,
  breached: 1,
  sla_compliance: 91.7,
  first_response_median_seconds: 1800,
  resolution_median_seconds: 14_400,
}
const PERFORMANCE = {
  assigned_30d: 18,
  resolved_30d: 15,
  first_replies_30d: 17,
  resolution_median_seconds_30d: 10_800,
  sla_compliance_30d: 93.3,
  reopen_rate_30d: 6.7,
}

/** The lifecycle trace of the first tickets: open 1 h, assigned 2 h, in progress (still running). */
export function lifecycleFixture(ticketId: string) {
  const ticket = db.tickets.find((row) => row.id === ticketId)
  const agent = AGENT_FIXTURES[0]
  return [
    {
      seq: 1,
      status: 'open',
      assigned_agent_id: null,
      team_id: null,
      priority_level: ticket?.priority_level ?? 'P3',
      starts_at: '2026-09-17T09:00:00Z',
      ends_at: '2026-09-17T10:00:00Z',
      wall_seconds: 3600,
      business_seconds: 3600,
      open: false,
    },
    {
      seq: 2,
      status: 'assigned',
      assigned_agent_id: agent?.id ?? null,
      team_id: agent?.teams[0]?.id ?? null,
      priority_level: ticket?.priority_level ?? 'P3',
      starts_at: '2026-09-17T10:00:00Z',
      ends_at: '2026-09-17T12:00:00Z',
      wall_seconds: 7200,
      business_seconds: 5400,
      open: false,
    },
    {
      seq: 3,
      status: 'in_progress',
      assigned_agent_id: agent?.id ?? null,
      team_id: agent?.teams[0]?.id ?? null,
      priority_level: ticket?.priority_level ?? 'P3',
      starts_at: '2026-09-17T12:00:00Z',
      ends_at: null,
      wall_seconds: 75_600,
      business_seconds: null,
      open: true,
    },
  ]
}

function overview(entity: string, id: string): Overview | undefined {
  switch (entity) {
    case 'tickets': {
      const ticket = db.tickets.find((row) => row.id === id)
      if (!ticket) return undefined
      return {
        entity,
        id,
        title: `#${ticket.number} ${ticket.title}`,
        metrics: {
          first_response_seconds: 1500,
          resolution_seconds: null,
          pending_seconds: 0,
          unassigned_seconds: 3600,
          reassignments: 1,
          reopens: 0,
          comments: 4,
          public_replies: 2,
          first_response_sla: 'met',
          resolution_sla: 'running',
        },
        related: {
          sla_timers: [
            {
              kind: 'first_response',
              cycle: 1,
              state: 'met',
              due_at: '2026-09-17T11:00:00Z',
              met_at: '2026-09-17T09:25:00Z',
              breached_at: null,
            },
            {
              kind: 'resolution',
              cycle: 1,
              state: 'running',
              due_at: '2026-09-19T09:00:00Z',
              met_at: null,
              breached_at: null,
            },
          ],
        },
        trends: { lifecycle: lifecycleFixture(id) },
      }
    }
    case 'contacts': {
      const contact = db.contacts.find((row) => row.id === id)
      if (!contact) return undefined
      return {
        entity,
        id,
        title: contact.name,
        metrics: TICKET_METRICS,
        related: {
          organization_id: contact.organization?.id ?? null,
          recent_tickets: db.tickets
            .filter((ticket) => ticket.contact_id === id)
            .slice(0, 10)
            .map(({ id: ticketId, number, title, status, created_at }) => ({
              id: ticketId,
              number,
              title,
              status,
              created_at,
            })),
        },
        trends: { tickets_per_week: weeks(3) },
      }
    }
    case 'organizations': {
      const organization = db.organizations.find((row) => row.id === id)
      if (!organization) return undefined
      return {
        entity,
        id,
        title: organization.name,
        metrics: { tier: organization.tier, contacts: organization.contacts_count, ...TICKET_METRICS },
        related: {
          top_categories: db.categories.map((category, index) => ({
            id: category.id,
            name: category.name,
            tickets: 9 - index * 3,
          })),
          tier_history: [
            { at: '2026-07-01T00:00:00Z', from: null, to: 'standard' },
            { at: '2026-08-15T08:00:00Z', from: 'standard', to: organization.tier },
          ],
        },
        trends: { tickets_per_week: weeks(5) },
      }
    }
    case 'agents': {
      const agent = db.agents.find((row) => row.id === id)
      if (!agent) return undefined
      return {
        entity,
        id,
        title: agent.user.name,
        metrics: {
          capacity: agent.capacity,
          availability: agent.availability,
          open_tickets: agent.active_ticket_count,
          ...PERFORMANCE,
        },
        related: {
          skills: agent.skills.map((skill) => ({ id: skill.id, name: skill.name, level: skill.level })),
          teams: agent.teams,
        },
        trends: { backlog_per_day: days(1) },
      }
    }
    case 'teams': {
      const team = db.teams.find((row) => row.id === id)
      if (!team) return undefined
      return {
        entity,
        id,
        title: team.name,
        metrics: PERFORMANCE,
        related: {
          members: team.members.map((member) => ({
            id: member.id,
            name: member.name,
            joined_at: '2026-08-01T00:00:00Z',
          })),
        },
        trends: { backlog_per_day: days(2) },
      }
    }
    case 'categories': {
      const category = db.categories.find((row) => row.id === id)
      if (!category) return undefined
      return {
        entity,
        id,
        title: category.name,
        metrics: TICKET_METRICS,
        related: {
          required_skills: category.required_skills.map((skill) => ({ id: skill.id, name: skill.name })),
          top_agents: db.agents
            .slice(0, 2)
            .map((agent, index) => ({ id: agent.id, name: agent.user.name, resolved: 7 - index * 2 })),
        },
        trends: { tickets_per_week: weeks(7) },
      }
    }
    default:
      return undefined
  }
}

function overviewHandler(entity: string) {
  return ({ params }: { params: Record<string, string | readonly string[] | undefined> }) => {
    const found = overview(entity, String(params.id))
    return found ? HttpResponse.json({ data: found }) : notFound()
  }
}

export const historyHandlers = [
  http.get(apiUrl('/tickets/{id}/overview'), overviewHandler('tickets')),
  http.get(apiUrl('/contacts/{id}/overview'), overviewHandler('contacts')),
  http.get(apiUrl('/organizations/{id}/overview'), overviewHandler('organizations')),
  http.get(apiUrl('/agents/{id}/overview'), overviewHandler('agents')),
  http.get(apiUrl('/teams/{id}/overview'), overviewHandler('teams')),
  http.get(apiUrl('/categories/{id}/overview'), overviewHandler('categories')),
  http.get(apiUrl('/history/{type}/{id}'), ({ params, request }) => {
    const type = String(params.type)
    if (!KNOWN_TYPES.has(type)) return notFound()
    const record = history(type, String(params.id))
    if (!record) return notFound()
    const newestFirst = [...record.changes].reverse()
    const offset = Number(new URL(request.url).searchParams.get('cursor') ?? 0)
    return HttpResponse.json({
      data: newestFirst.slice(offset, offset + PAGE),
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: null,
        per_page: PAGE,
        next_cursor: offset + PAGE < newestFirst.length ? String(offset + PAGE) : null,
        prev_cursor: offset > 0 ? String(Math.max(0, offset - PAGE)) : null,
      },
    })
  }),
  http.get(apiUrl('/history/{type}/{id}/as-of'), ({ params, request }) => {
    const type = String(params.type)
    if (!KNOWN_TYPES.has(type)) return notFound()
    const record = history(type, String(params.id))
    if (!record) return notFound()
    const at = new URL(request.url).searchParams.get('at') ?? ''
    const instant = Date.parse(at)
    if (Number.isNaN(instant)) return validationFailed({ at: ['Give an ISO 8601 date and time.'] })
    const then = asOf(record, instant)
    const now = record.current
    const differences: Record<string, { then: unknown; now: unknown }> = {}
    for (const attribute of new Set([...Object.keys(then ?? {}), ...Object.keys(now ?? {})])) {
      const before = then?.[attribute] ?? null
      const after = now?.[attribute] ?? null
      if (JSON.stringify(before) !== JSON.stringify(after))
        // biome-ignore lint/suspicious/noThenProperty: the API's own key (AsOfResource.differences).
        differences[attribute] = { then: before, now: after }
    }
    return HttpResponse.json({
      data: {
        entity_type: type,
        entity_id: String(params.id),
        at: new Date(instant).toISOString(),
        exists: then !== null,
        attributes: then,
        differences,
        versions_after: record.changes.filter((entry) => Date.parse(entry.occurred_at) > instant).length,
      },
    })
  }),
]

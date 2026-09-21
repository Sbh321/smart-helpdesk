import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import {
  AGENT_FIXTURES,
  db,
  NOW,
  nextId,
  type TicketEventResource,
  type TicketResource,
  tagsByName,
} from './data'
import { apiUrl, problem } from './handlers'
import { compare, paginate, validateListQuery, validationFailed } from './list'

export {
  AGENT_FIXTURES,
  CATEGORY_FIXTURES,
  DUPLICATE_SUGGESTED_FIXTURES,
  TEAM_FIXTURES,
  TICKET_FIXTURES,
  TICKET_SLA_FIXTURES,
} from './data'

/**
 * Tickets and categories as the backend implements them (docs/07-api/pagination-filtering.md §Ticket list
 * filters): `filter[status]` (with the `active` alias), `filter[priority]`, `filter[assignee_id]` (ids,
 * `unassigned`, `me`), `filter[team_id]` (ids, `none`), `filter[category_id]`, `filter[organization_id]`,
 * `filter[contact_id]`, `filter[tag]` (slugs), `filter[sla_state]` (latest resolution timer),
 * `filter[has_duplicate_suggestion]=true`, `filter[created_between]` (inclusive dates), `filter[impact]`,
 * `filter[urgency]`, `filter[number]`, `search` (title words or the number), `include=contact,category`,
 * default sort `-priority_score,-created_at`; `sla_due_at` puts tickets without a running timer last.
 */
const SORTABLE = [
  'priority_score',
  'priority_level',
  'created_at',
  'updated_at',
  'number',
  'status',
  'sla_due_at',
] as const
const FILTERS = [
  'status',
  'priority',
  'assignee_id',
  'team_id',
  'category_id',
  'organization_id',
  'contact_id',
  'tag',
  'sla_state',
  'has_duplicate_suggestion',
  'created_between',
  'updated_since',
  'impact',
  'urgency',
  'number',
] as const

/** `filter[assignee_id]=me` in the mock: the session's Agent profile is always the first Agent fixture. */
export const MOCK_ME_AGENT_ID = AGENT_FIXTURES[0]?.id ?? ''
const RUNNING_STATES = ['running', 'warning', 'breached', 'paused']

function slaDueAt(ticket: TicketResource): string | null {
  const timer = db.ticketSla[ticket.id]
  return timer && RUNNING_STATES.includes(timer.state) ? timer.due_at : null
}

/** A nullable id filter with its "none" token (`unassigned`, `none`). */
function matchesNullable(value: string | null, wanted: string[], nullToken: string): boolean {
  return (value === null && wanted.includes(nullToken)) || (value !== null && wanted.includes(value))
}

function sortTickets(rows: TicketResource[], sort: string): TicketResource[] {
  return rows.sort((a, b) => {
    for (const part of sort.split(',')) {
      const desc = part.startsWith('-')
      const field = desc ? part.slice(1) : part
      if (field === 'sla_due_at') {
        const [left, right] = [slaDueAt(a), slaDueAt(b)]
        // NULLS LAST in both directions, as the API orders it.
        if (left === null || right === null) {
          if (left !== right) return left === null ? 1 : -1
          continue
        }
        const result = compare(left, right)
        if (result !== 0) return desc ? -result : result
        continue
      }
      const result = compare(a[field as keyof TicketResource], b[field as keyof TicketResource])
      if (result !== 0) return desc ? -result : result
    }
    return a.id.localeCompare(b.id)
  })
}
export const TICKET_DEFAULT_SORT = '-priority_score,-created_at'
const ACTIVE = ['open', 'assigned', 'in_progress', 'pending']

export function withIncludes(ticket: TicketResource, includes: readonly string[]): TicketResource {
  const result = { ...ticket, allowed_transitions: allowedTransitions(ticket) }
  if (includes.includes('contact')) {
    const contact = db.contacts.find((c) => c.id === ticket.contact_id)
    if (contact) result.contact = { id: contact.id, name: contact.name, email: contact.email }
  }
  if (includes.includes('category')) {
    const category = db.categories.find((c) => c.id === ticket.category_id)
    if (category) result.category = { id: category.id, name: category.name }
  }
  if (includes.includes('organization')) {
    const organization = db.organizations.find((row) => row.id === ticket.organization_id)
    result.organization = organization
      ? { id: organization.id, name: organization.name, tier: organization.tier }
      : null
  }
  return result
}

/** Contract fixture for lifecycle targets; the real API also filters these by user permissions. */
function allowedTransitions(ticket: TicketResource): TicketResource['status'][] {
  switch (ticket.status) {
    case 'assigned':
      return ['in_progress', 'pending', 'resolved']
    case 'in_progress':
      return ['pending', 'resolved']
    case 'pending':
      return ['in_progress', 'resolved']
    case 'resolved':
      return ['closed', 'in_progress']
    case 'closed':
      return ['in_progress']
    default:
      return []
  }
}

export function queryTickets(url: URL, tickets: TicketResource[] = db.tickets): TicketResource[] {
  let rows = [...tickets]
  const statuses = url.searchParams.get('filter[status]')?.split(',')
  if (statuses) {
    const wanted = statuses.flatMap((status) => (status === 'active' ? ACTIVE : [status]))
    rows = rows.filter((ticket) => wanted.includes(ticket.status))
  }
  const levels = url.searchParams.get('filter[priority]')?.split(',')
  if (levels) rows = rows.filter((ticket) => levels.includes(ticket.priority_level))
  const assignees = url.searchParams
    .get('filter[assignee_id]')
    ?.split(',')
    .map((value) => (value === 'me' ? MOCK_ME_AGENT_ID : value))
  if (assignees) {
    rows = rows.filter((ticket) => matchesNullable(ticket.assigned_agent_id, assignees, 'unassigned'))
  }
  const teams = url.searchParams.get('filter[team_id]')?.split(',')
  if (teams) rows = rows.filter((ticket) => matchesNullable(ticket.team_id, teams, 'none'))
  const categories = url.searchParams.get('filter[category_id]')?.split(',')
  if (categories) rows = rows.filter((ticket) => categories.includes(ticket.category_id))
  const organizations = url.searchParams.get('filter[organization_id]')?.split(',')
  if (organizations) {
    rows = rows.filter(
      (ticket) => ticket.organization_id !== null && organizations.includes(ticket.organization_id),
    )
  }
  const contacts = url.searchParams.get('filter[contact_id]')?.split(',')
  if (contacts) rows = rows.filter((ticket) => contacts.includes(ticket.contact_id))
  const tags = url.searchParams.get('filter[tag]')?.split(',')
  if (tags) rows = rows.filter((ticket) => (ticket.tags ?? []).some((tag) => tags.includes(tag.slug)))
  const slaStates = url.searchParams.get('filter[sla_state]')?.split(',')
  if (slaStates) {
    rows = rows.filter((ticket) => {
      const timer = db.ticketSla[ticket.id]
      return timer !== undefined && slaStates.includes(timer.state)
    })
  }
  if (url.searchParams.get('filter[has_duplicate_suggestion]') === 'true') {
    rows = rows.filter(
      (ticket) =>
        db.duplicateSuggested.includes(ticket.id) ||
        (db.duplicates[ticket.id] ?? []).some((row) => row.decision === 'pending'),
    )
  }
  for (const key of ['impact', 'urgency', 'number'] as const) {
    const values = url.searchParams.get(`filter[${key}]`)?.split(',').map(Number)
    if (values) rows = rows.filter((ticket) => values.includes(ticket[key]))
  }
  const between = url.searchParams.get('filter[created_between]')?.split(',')
  if (between?.[0] && between[1]) {
    const [from, to] = between
    rows = rows.filter(
      (ticket) => ticket.created_at.slice(0, 10) >= from && ticket.created_at.slice(0, 10) <= to,
    )
  }
  const search = url.searchParams.get('search')?.trim().toLowerCase()
  if (search) {
    rows = rows.filter(
      (ticket) => ticket.title.toLowerCase().includes(search) || String(ticket.number) === search,
    )
  }
  return sortTickets(rows, url.searchParams.get('sort') ?? TICKET_DEFAULT_SORT)
}

export function ticketsHandler(tickets?: TicketResource[]) {
  return http.get(apiUrl('/tickets'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: SORTABLE,
      filters: FILTERS,
      defaultSort: TICKET_DEFAULT_SORT,
    })
    if (invalid) return invalid
    const includes = url.searchParams.get('include')?.split(',') ?? []
    const page = paginate(url, queryTickets(url, tickets ?? db.tickets))
    return HttpResponse.json({ ...page, data: page.data.map((ticket) => withIncludes(ticket, includes)) })
  })
}

type TicketBody = {
  title?: string
  description?: string
  contact_id?: string
  category_id?: string
  impact?: number
  urgency?: number
  tags?: string[]
}

function validateTicket(body: TicketBody): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if ((body.title?.trim() ?? '') === '') errors.title = ['The title field is required.']
  if ((body.description?.trim() ?? '') === '') errors.description = ['The description field is required.']
  if (!db.contacts.some((c) => c.id === body.contact_id))
    errors.contact_id = ['The selected contact id is invalid.']
  if (!db.categories.some((c) => c.id === body.category_id)) {
    errors.category_id = ['The selected category id is invalid.']
  }
  for (const key of ['impact', 'urgency'] as const) {
    const value = body[key]
    if (!(typeof value === 'number' && value >= 1 && value <= 4))
      errors[key] = [`The ${key} field must be 1 to 4.`]
  }
  return errors
}

type BulkRow = components['schemas']['BulkRowResource']
type BulkTransitionBody = Partial<components['schemas']['BulkTransitionRequest']>
type BulkAssignBody = Partial<components['schemas']['BulkAssignRequest']>
const STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed']

const okRow = (ticketId: string, details: Record<string, unknown> = {}): BulkRow => ({
  ticket_id: ticketId,
  ok: true,
  code: null,
  detail: null,
  details,
})
const failedRow = (ticketId: string, code: string, detail: string): BulkRow => ({
  ticket_id: ticketId,
  ok: false,
  code,
  detail,
  details: {},
})

function bulkAnswer(rows: BulkRow[]): Response {
  const succeeded = rows.filter((row) => row.ok).length
  return HttpResponse.json({
    data: rows,
    meta: { total: rows.length, succeeded, failed: rows.length - succeeded },
  })
}

/** `ticket_ids`: 1–100 distinct ids, as `BulkTransitionRequest` and `BulkAssignRequest` validate them. */
function validateTicketIds(ids: unknown): Record<string, string[]> {
  if (!Array.isArray(ids) || ids.length === 0) return { ticket_ids: ['The ticket ids field is required.'] }
  if (ids.length > 100) return { ticket_ids: ['The ticket ids field must not have more than 100 items.'] }
  if (new Set(ids).size !== ids.length)
    return { 'ticket_ids.0': ['The ticket_ids.0 field has a duplicate value.'] }
  return {}
}

/**
 * The bulk endpoints (M2-11): each ticket on its own, 200 with a row per ticket. Transitions follow the
 * single-ticket rules (an open ticket moves like an assigned one); assigning refuses resolved and closed
 * tickets; auto-assign picks the least loaded available Agent holding the category's required Skills
 * (nobody holds Networking or Accounts, so Technical and Account tickets fail `no_eligible_agent`) and
 * refuses a ticket that already has an Agent.
 */
const bulkHandlers = [
  http.post(apiUrl('/tickets/bulk/transition'), async ({ request }) => {
    const body = (await request.json()) as BulkTransitionBody
    const errors = validateTicketIds(body.ticket_ids)
    if (!body.status || !STATUSES.includes(body.status)) errors.status = ['The selected status is invalid.']
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const target = body.status as TicketResource['status']
    const rows = (body.ticket_ids ?? []).map((id) => {
      const ticket = db.tickets.find((row) => row.id === id)
      if (!ticket) return failedRow(id, 'not_found', 'The ticket does not exist.')
      const allowed = allowedTransitions(
        ticket.status === 'open' ? { ...ticket, status: 'assigned' } : ticket,
      )
      if (!allowed.includes(target)) {
        return failedRow(id, 'invalid_transition', `A ticket cannot move from ${ticket.status} to ${target}.`)
      }
      if (target === 'resolved' && !body.comment?.trim()) {
        return failedRow(id, 'resolution_comment_required', 'Explain how the request was resolved.')
      }
      ticket.status = target
      ticket.updated_at = NOW
      ticket.resolved_at = target === 'resolved' || target === 'closed' ? (ticket.resolved_at ?? NOW) : null
      ticket.closed_at = target === 'closed' ? NOW : null
      return okRow(id, { number: ticket.number, status: ticket.status })
    })
    return bulkAnswer(rows)
  }),
  http.post(apiUrl('/tickets/bulk/assign'), async ({ request }) => {
    const body = (await request.json()) as BulkAssignBody
    const errors = validateTicketIds(body.ticket_ids)
    const manual = Boolean(body.agent_id || body.team_id)
    if (body.auto === true && manual) errors.auto = ['Choose an Agent or Team, or auto-assign; not both.']
    if (body.auto !== true && !manual) errors.agent_id = ['Choose an Agent or a Team.']
    const agent = body.agent_id ? db.agents.find((row) => row.id === body.agent_id) : undefined
    if (body.agent_id && !agent) errors.agent_id = ['The selected agent id is invalid.']
    if (body.team_id && !db.teams.some((row) => row.id === body.team_id)) {
      errors.team_id = ['The selected team id is invalid.']
    }
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const rows = (body.ticket_ids ?? []).map((id) => {
      const ticket = db.tickets.find((row) => row.id === id)
      if (!ticket) return failedRow(id, 'not_found', 'The ticket does not exist.')
      if (ticket.status === 'resolved' || ticket.status === 'closed') {
        return failedRow(id, 'invalid_transition', `A ${ticket.status} ticket cannot be assigned.`)
      }
      let assignee = agent
      if (body.auto === true) {
        if (ticket.assigned_agent_id)
          return failedRow(id, 'already_assigned', 'This ticket already has an Agent.')
        const required = db.categories.find((row) => row.id === ticket.category_id)?.required_skills ?? []
        assignee = db.agents
          .filter(
            (row) =>
              row.availability === 'available' &&
              row.active_ticket_count < row.capacity &&
              required.every((skill) => row.skills.some((held) => held.id === skill.id)),
          )
          .sort((a, b) => a.active_ticket_count / a.capacity - b.active_ticket_count / b.capacity)[0]
        if (!assignee) {
          return failedRow(id, 'no_eligible_agent', 'No agent is eligible for this ticket.')
        }
      }
      if (body.team_id) ticket.team_id = body.team_id
      if (assignee) {
        ticket.assigned_agent_id = assignee.id
        ticket.team_id = body.team_id ?? assignee.teams[0]?.id ?? ticket.team_id
        if (ticket.status === 'open') ticket.status = 'assigned'
        assignee.active_ticket_count += 1
      }
      ticket.updated_at = NOW
      return okRow(id, { number: ticket.number, agent_id: ticket.assigned_agent_id, team_id: ticket.team_id })
    })
    return bulkAnswer(rows)
  }),
]

export const ticketHandlers = [
  ...bulkHandlers,
  ticketsHandler(),
  http.post(apiUrl('/tickets'), async ({ request }) => {
    const body = (await request.json()) as TicketBody
    const errors = validateTicket(body)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    const contact = db.contacts.find((c) => c.id === body.contact_id)
    const number = Math.max(0, ...db.tickets.map((ticket) => ticket.number)) + 1
    const score = Math.round(((body.impact ?? 1) + (body.urgency ?? 1)) * 10)
    const level = score >= 70 ? 'P1' : score >= 50 ? 'P2' : score >= 30 ? 'P3' : 'P4'
    const ticket: TicketResource = {
      id: nextId(5),
      number,
      title: body.title?.trim() ?? '',
      description: body.description ?? '',
      status: 'open',
      impact: body.impact ?? 1,
      urgency: body.urgency ?? 1,
      priority_score: score,
      priority_level: level,
      priority_computed_level: level,
      priority_overridden: false,
      priority_explanation: {},
      priority_override_reason: null,
      allowed_transitions: [],
      contact_id: body.contact_id ?? '',
      organization_id: contact?.organization?.id ?? null,
      category_id: body.category_id ?? '',
      team_id: null,
      assigned_agent_id: null,
      duplicate_of_id: null,
      created_via: 'agent',
      tags: tagsByName(body.tags ?? []),
      resolved_at: null,
      closed_at: null,
      created_at: NOW,
      updated_at: NOW,
    }
    db.tickets.push(ticket)
    return HttpResponse.json({ data: withIncludes(ticket, ['contact', 'category']) }, { status: 201 })
  }),
  http.get(apiUrl('/tickets/{ticket}'), ({ params }) => {
    const ticket = db.tickets.find((t) => t.id === params.ticket)
    if (!ticket) return problem(404, 'not_found', { title: 'Not found' })
    return HttpResponse.json({
      data: { ...withIncludes(ticket, ['contact', 'category', 'organization']), tags: ticket.tags ?? [] },
    })
  }),
  http.patch(apiUrl('/tickets/{ticket}'), async ({ params, request }) => {
    const ticket = db.tickets.find((row) => row.id === params.ticket)
    if (!ticket) return problem(404, 'not_found')
    const body = (await request.json()) as Partial<TicketBody>
    const errors = validateTicket({ ...ticket, tags: ticket.tags?.map((tag) => tag.name), ...body })
    if (Object.keys(errors).length) return validationFailed(errors)
    const { tags, ...fields } = body
    Object.assign(ticket, fields, { updated_at: NOW })
    if (tags) ticket.tags = tagsByName(tags)
    return HttpResponse.json({ data: withIncludes(ticket, ['contact', 'category', 'organization']) })
  }),
  http.post(apiUrl('/tickets/{ticket}/transition'), async ({ params, request }) => {
    const ticket = db.tickets.find((row) => row.id === params.ticket)
    if (!ticket) return problem(404, 'not_found')
    const body = (await request.json()) as { status: TicketResource['status']; comment?: string }
    if (!allowedTransitions(ticket).includes(body.status)) return problem(422, 'invalid_transition')
    if (body.status === 'resolved' && !body.comment?.trim())
      return validationFailed({ comment: ['Explain how the request was resolved.'] })
    const from = ticket.status
    ticket.status = body.status
    ticket.updated_at = NOW
    ticket.resolved_at =
      body.status === 'resolved' || body.status === 'closed' ? (ticket.resolved_at ?? NOW) : null
    ticket.closed_at = body.status === 'closed' ? NOW : null
    const event: TicketEventResource = {
      id: nextId(6),
      type: 'status_changed',
      actor_type: 'user',
      actor_id: null,
      old_values: { status: from },
      new_values: { status: body.status },
      note: body.comment ?? null,
      created_at: NOW,
    }
    db.ticketEvents[ticket.id] = [event, ...(db.ticketEvents[ticket.id] ?? [])]
    return HttpResponse.json({ data: withIncludes(ticket, ['contact', 'category', 'organization']) })
  }),
  http.get(apiUrl('/tickets/{ticket}/history'), ({ params, request }) => {
    const ticket = db.tickets.find((t) => t.id === params.ticket)
    if (!ticket) return problem(404, 'not_found', { title: 'Not found' })
    const created: TicketEventResource = {
      id: nextId(6),
      type: 'created',
      actor_type: 'user',
      actor_id: null,
      old_values: {},
      new_values: { status: 'open', priority_level: ticket.priority_level },
      note: null,
      created_at: ticket.created_at,
    }
    const url = new URL(request.url)
    const events = [...(db.ticketEvents[ticket.id] ?? []), created]
    const offset = Number(url.searchParams.get('cursor') ?? 0)
    const size = Number(url.searchParams.get('per_page') ?? 50)
    return HttpResponse.json({
      data: events.slice(offset, offset + size),
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        path: null,
        per_page: size,
        next_cursor: offset + size < events.length ? String(offset + size) : null,
        prev_cursor: null,
      },
    })
  }),
  http.get(apiUrl('/categories'), () => HttpResponse.json({ data: db.categories })),
]

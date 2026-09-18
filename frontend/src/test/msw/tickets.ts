import { HttpResponse, http } from 'msw'
import { db, NOW, nextId, type TicketEventResource, type TicketResource, tagsByName } from './data'
import { apiUrl, problem } from './handlers'
import { paginate, sortRows, validateListQuery, validationFailed } from './list'

export { CATEGORY_FIXTURES, TICKET_FIXTURES } from './data'

/**
 * Tickets and categories as the backend implements them (docs/07-api/pagination-filtering.md §Ticket list
 * filters): `filter[status]` (with the `active` alias), `filter[priority]`, `filter[category_id]`,
 * `filter[created_between]` (inclusive dates), `search` (title words or the number), `include=contact,
 * category`, default sort `-priority_score,-created_at`.
 */
const SORTABLE = ['priority_score', 'priority_level', 'created_at', 'updated_at', 'number', 'status'] as const
const FILTERS = [
  'status',
  'priority',
  'assignee_id',
  'team_id',
  'category_id',
  'organization_id',
  'contact_id',
  'tag',
  'created_between',
  'updated_since',
] as const
export const TICKET_DEFAULT_SORT = '-priority_score,-created_at'
const ACTIVE = ['open', 'assigned', 'in_progress', 'pending']

function withIncludes(ticket: TicketResource, includes: readonly string[]): TicketResource {
  const result = { ...ticket }
  if (includes.includes('contact')) {
    const contact = db.contacts.find((c) => c.id === ticket.contact_id)
    if (contact) result.contact = { id: contact.id, name: contact.name, email: contact.email }
  }
  if (includes.includes('category')) {
    const category = db.categories.find((c) => c.id === ticket.category_id)
    if (category) result.category = { id: category.id, name: category.name }
  }
  return result
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
  const categories = url.searchParams.get('filter[category_id]')?.split(',')
  if (categories) rows = rows.filter((ticket) => categories.includes(ticket.category_id))
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
  return sortRows(rows, url.searchParams.get('sort') ?? TICKET_DEFAULT_SORT)
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

export const ticketHandlers = [
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
      data: { ...withIncludes(ticket, ['contact', 'category']), tags: ticket.tags ?? [] },
    })
  }),
  http.get(apiUrl('/tickets/{ticket}/history'), ({ params }) => {
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
    return HttpResponse.json({
      data: [created],
      links: { first: null, last: null, prev: null, next: null },
      meta: { path: null, per_page: 50, next_cursor: null, prev_cursor: null },
    })
  }),
  http.get(apiUrl('/categories'), () => HttpResponse.json({ data: db.categories })),
]

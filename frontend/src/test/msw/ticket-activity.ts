import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { type CommentResource, db, NOW, nextId, type TicketResource } from './data'
import { apiUrl, problem } from './handlers'
import { paginate, validationFailed } from './list'
import { withIncludes } from './tickets'

/** Comments, attachments, duplicates, assignment and priority override of one ticket (M2-04…M2-10). */
type CommentInput = components['schemas']['AddCommentRequest']
type AssignmentPreview = components['schemas']['AssignmentPreviewResource']

const notFound = () => problem(404, 'not_found', { title: 'Not found' })
const DETAIL = ['contact', 'category', 'organization'] as const

function findTicket(id: unknown): TicketResource | undefined {
  return db.tickets.find((row) => row.id === id)
}

function detail(ticket: TicketResource) {
  return HttpResponse.json({ data: { ...withIncludes(ticket, DETAIL), tags: ticket.tags ?? [] } })
}

/** The baseline's shape: available Agents with room, least loaded first; everybody else with a reason. */
export function assignmentPreview(ticket: TicketResource): AssignmentPreview {
  const ranking = db.agents
    .filter((agent) => agent.availability === 'available' && agent.active_ticket_count < agent.capacity)
    .map((agent) => ({
      agent_id: agent.id,
      agent_name: agent.user.name,
      open_tickets: agent.active_ticket_count,
      capacity: agent.capacity,
      load: agent.active_ticket_count / agent.capacity,
      last_assigned_at: agent.last_assigned_at,
    }))
    .sort((a, b) => a.load - b.load)
  const excluded = db.agents
    .filter((agent) => !ranking.some((row) => row.agent_id === agent.id))
    .map((agent) => ({
      agent_id: agent.id,
      reason: agent.availability === 'available' ? 'at_capacity' : 'not_available',
    }))
  return {
    strategy: 'least_loaded',
    strategy_version: '1.0.0',
    ticket_id: ticket.id,
    agent_id: ranking[0]?.agent_id ?? null,
    outcome: ranking.length > 0 ? 'assigned' : 'no_eligible_agent',
    ranking,
    excluded,
  }
}

function assign(ticket: TicketResource, agentId: string): Response {
  const agent = db.agents.find((row) => row.id === agentId)
  if (!agent) return validationFailed({ agent_id: ['The selected agent id is invalid.'] })
  ticket.assigned_agent_id = agent.id
  ticket.team_id = agent.teams[0]?.id ?? ticket.team_id
  if (ticket.status === 'open') ticket.status = 'assigned'
  agent.active_ticket_count += 1
  return detail(ticket)
}

export const ticketActivityHandlers = [
  http.get(apiUrl('/tickets/{ticket}/comments'), ({ params, request }) => {
    if (!findTicket(params.ticket)) return notFound()
    return HttpResponse.json(paginate(new URL(request.url), db.comments[String(params.ticket)] ?? []))
  }),
  http.post(apiUrl('/tickets/{ticket}/comments'), async ({ params, request }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    const body = (await request.json()) as CommentInput
    if (!body.body?.trim()) return validationFailed({ body: ['The body field is required.'] })
    const comment: CommentResource = {
      id: nextId(15),
      ticket_id: ticket.id,
      visibility: body.visibility,
      author_type: 'user',
      author_id: null,
      body: body.body,
      attachments: db.media.filter((item) => body.media_ids?.includes(item.id)),
      created_at: NOW,
      updated_at: NOW,
    }
    db.comments[ticket.id] = [...(db.comments[ticket.id] ?? []), comment]
    return HttpResponse.json({ data: comment }, { status: 201 })
  }),
  http.get(apiUrl('/tickets/{ticket}/attachments'), ({ params }) => {
    const ids = db.ticketAttachments[String(params.ticket)] ?? []
    return HttpResponse.json({ data: db.media.filter((item) => ids.includes(item.id)) })
  }),
  http.post(apiUrl('/tickets/{ticket}/attachments'), async ({ params, request }) => {
    const key = String(params.ticket)
    const body = (await request.json()) as { media_ids: string[] }
    const ids = new Set([...(db.ticketAttachments[key] ?? []), ...body.media_ids])
    db.ticketAttachments[key] = [...ids]
    return HttpResponse.json({ data: db.media.filter((item) => ids.has(item.id)) })
  }),
  http.delete(apiUrl('/tickets/{ticket}/attachments/{media}'), ({ params }) => {
    const key = String(params.ticket)
    db.ticketAttachments[key] = (db.ticketAttachments[key] ?? []).filter((id) => id !== params.media)
    return new HttpResponse(null, { status: 204 })
  }),
  http.post(apiUrl('/tickets/preview-duplicates'), async ({ request }) => {
    const body = (await request.json()) as { title: string }
    const words = body.title
      .toLowerCase()
      .split(/\W+/)
      .filter((word) => word.length > 3)
    const matches = db.tickets
      .map((ticket) => ({
        ticket,
        shared: words.filter((word) => ticket.title.toLowerCase().includes(word)),
      }))
      .filter((row) => row.shared.length >= 2)
      .slice(0, 3)
      .map((row) => ({
        ticket_id: row.ticket.id,
        number: row.ticket.number,
        title: row.ticket.title,
        score: row.shared.length / Math.max(words.length, 1),
        shared_words: row.shared,
      }))
    return HttpResponse.json({
      data: { strategy: 'word_overlap', strategy_version: '1.0.0', candidates_compared: 60, matches },
    })
  }),
  http.get(apiUrl('/tickets/{ticket}/duplicates'), ({ params }) =>
    HttpResponse.json({ data: db.duplicates[String(params.ticket)] ?? [] }),
  ),
  http.post(apiUrl('/tickets/{ticket}/duplicates/{candidate}/dismiss'), ({ params }) => {
    const row = (db.duplicates[String(params.ticket)] ?? []).find(
      (item) => item.candidate_ticket_id === params.candidate,
    )
    if (!row) return notFound()
    row.decision = 'dismissed'
    row.decided_at = NOW
    return HttpResponse.json({ data: row })
  }),
  http.post(apiUrl('/tickets/{ticket}/mark-duplicate'), async ({ params, request }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    const body = (await request.json()) as { candidate_ticket_id: string }
    if (!findTicket(body.candidate_ticket_id))
      return validationFailed({ candidate_ticket_id: ['The selected ticket is invalid.'] })
    ticket.duplicate_of_id = body.candidate_ticket_id
    ticket.status = 'closed'
    ticket.closed_at = NOW
    for (const row of db.duplicates[ticket.id] ?? []) {
      if (row.candidate_ticket_id === body.candidate_ticket_id) {
        row.decision = 'confirmed'
        row.decided_at = NOW
      }
    }
    return detail(ticket)
  }),
  http.get(apiUrl('/tickets/{ticket}/assignment-candidates'), ({ params }) => {
    const ticket = findTicket(params.ticket)
    return ticket ? HttpResponse.json({ data: assignmentPreview(ticket) }) : notFound()
  }),
  http.post(apiUrl('/tickets/{ticket}/assign'), async ({ params, request }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    if (ticket.assigned_agent_id)
      return problem(409, 'already_assigned', { detail: 'This ticket already has an Agent.' })
    return assign(ticket, ((await request.json()) as { agent_id: string }).agent_id)
  }),
  http.post(apiUrl('/tickets/{ticket}/auto-assign'), ({ params }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    if (ticket.assigned_agent_id)
      return problem(409, 'already_assigned', { detail: 'This ticket already has an Agent.' })
    const preview = assignmentPreview(ticket)
    return preview.agent_id
      ? assign(ticket, preview.agent_id)
      : problem(422, 'no_eligible_agent', {
          detail: 'No agent is eligible for this ticket. Assign it manually or change the team.',
          meta: { ticket_id: ticket.id, team_id: ticket.team_id, exclusions: preview.excluded },
        })
  }),
  http.post(apiUrl('/tickets/{ticket}/unassign'), ({ params }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    const agent = db.agents.find((row) => row.id === ticket.assigned_agent_id)
    if (agent) agent.active_ticket_count = Math.max(0, agent.active_ticket_count - 1)
    ticket.assigned_agent_id = null
    if (ticket.status === 'assigned') ticket.status = 'open'
    return detail(ticket)
  }),
  http.post(apiUrl('/tickets/{ticket}/priority'), async ({ params, request }) => {
    const ticket = findTicket(params.ticket)
    if (!ticket) return notFound()
    const body = (await request.json()) as components['schemas']['OverridePriorityRequest']
    if (body.level !== null && !body.reason?.trim())
      return validationFailed({ reason: ['The reason field is required when a level is set.'] })
    ticket.priority_overridden = body.level !== null
    ticket.priority_level = body.level ?? ticket.priority_computed_level
    ticket.priority_override_reason = body.level === null ? null : (body.reason ?? null)
    return detail(ticket)
  }),
  http.post(apiUrl('/settings/automation/priority/preview'), async ({ request }) => {
    const body = (await request.json()) as components['schemas']['PreviewPriorityRequest']
    const sum = Object.values(body.weights).reduce((total, value) => total + value, 0)
    if (Math.abs(sum - 1) > 0.001) return validationFailed({ weights: ['The weights must add up to 1.'] })
    if (!(body.thresholds.P1 > body.thresholds.P2 && body.thresholds.P2 > body.thresholds.P3))
      return validationFailed({ 'thresholds.P2': ['The P2 threshold must be between P3 and P1.'] })
    const tier = { standard: 0, premium: 0.5, enterprise: 1 }
    return HttpResponse.json({
      data: body.samples.map((sample) => {
        const score =
          100 *
          (body.weights.impact * ((sample.impact - 1) / 3) +
            body.weights.urgency * ((sample.urgency - 1) / 3) +
            body.weights.tier * tier[sample.tier] +
            body.weights.age * Math.min(1, sample.hours_waited / body.age_full_hours))
        const level =
          score >= body.thresholds.P1
            ? 'P1'
            : score >= body.thresholds.P2
              ? 'P2'
              : score >= body.thresholds.P3
                ? 'P3'
                : 'P4'
        return {
          strategy: 'basic_weighted',
          strategy_version: '1.0.0',
          score,
          level,
          parts: [],
          settings: {},
        }
      }),
    })
  }),
]

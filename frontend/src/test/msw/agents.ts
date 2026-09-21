import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import {
  type AgentResource,
  type AgentShiftResource,
  db,
  nextId,
  type SkillResource,
  type TeamResource,
} from './data'
import { apiUrl, problem } from './handlers'
import { paginate, sortRows, validateListQuery, validationFailed } from './list'

type AgentInput = components['schemas']['SaveAgentRequest']
type SkillInput = components['schemas']['SaveSkillRequest']
type TeamInput = components['schemas']['SaveTeamRequest']
type CategoryInput = components['schemas']['SaveCategoryRequest']
type ShiftInput = components['schemas']['ReplaceAgentShiftsRequest']

const notFound = () => problem(404, 'not_found', { title: 'Not found' })

function directoryList<T extends { id: string; name?: string }>(request: Request, rows: T[]) {
  const url = new URL(request.url)
  const invalid = validateListQuery(url, {
    sortable: ['name', 'created_at'],
    filters: [],
    defaultSort: 'name',
  })
  if (invalid) return invalid
  const search = url.searchParams.get('search')?.trim().toLowerCase()
  const filtered = search ? rows.filter((row) => row.name?.toLowerCase().includes(search)) : [...rows]
  return HttpResponse.json(paginate(url, sortRows(filtered, url.searchParams.get('sort') ?? 'name')))
}

function applyAgent(agent: AgentResource, body: Partial<AgentInput>): AgentResource {
  return {
    ...agent,
    capacity: body.capacity ?? agent.capacity,
    availability: body.availability ?? agent.availability,
    skills: body.skills
      ? body.skills.flatMap((assignment) => {
          const skill = db.skills.find((row) => row.id === assignment.skill_id)
          return skill ? [{ ...skill, level: assignment.level }] : []
        })
      : agent.skills,
    teams: body.team_ids
      ? body.team_ids.flatMap((id) => {
          const team = db.teams.find((row) => row.id === id)
          return team ? [{ id: team.id, name: team.name }] : []
        })
      : agent.teams,
  }
}

export const agentHandlers = [
  http.get(apiUrl('/agents'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: ['created_at', 'capacity', 'availability'],
      filters: ['availability', 'team_id', 'skill_id'],
      defaultSort: 'created_at',
    })
    if (invalid) return invalid
    let rows = [...db.agents]
    const search = url.searchParams.get('search')?.trim().toLowerCase()
    if (search)
      rows = rows.filter((agent) => `${agent.user.name} ${agent.user.email}`.toLowerCase().includes(search))
    const availability = url.searchParams.get('filter[availability]')?.split(',')
    if (availability) rows = rows.filter((agent) => availability.includes(agent.availability))
    return HttpResponse.json(paginate(url, sortRows(rows, url.searchParams.get('sort') ?? 'created_at')))
  }),
  http.get(apiUrl('/agents/available-users'), () =>
    HttpResponse.json({
      data: [{ id: nextId(8), name: 'New Agent', email: 'new.agent@acme.test' }],
    }),
  ),
  http.post(apiUrl('/agents'), async ({ request }) => {
    const body = (await request.json()) as AgentInput
    if (body.capacity < 1 || body.capacity > 100) return validationFailed({ capacity: ['Invalid capacity.'] })
    const agent = applyAgent(
      {
        id: nextId(7),
        user: { id: body.user_id, name: 'New Agent', email: 'new.agent@acme.test', is_active: true },
        capacity: body.capacity,
        availability: body.availability,
        active_ticket_count: 0,
        last_assigned_at: null,
        skills: [],
        teams: [],
      },
      body,
    )
    db.agents.push(agent)
    return HttpResponse.json({ data: agent }, { status: 201 })
  }),
  http.get(apiUrl('/agents/{agent}'), ({ params }) => {
    const agent = db.agents.find((row) => row.id === params.agent)
    return agent ? HttpResponse.json({ data: agent }) : notFound()
  }),
  http.patch(apiUrl('/agents/{agent}'), async ({ params, request }) => {
    const index = db.agents.findIndex((row) => row.id === params.agent)
    const agent = db.agents[index]
    if (!agent) return notFound()
    const updated = applyAgent(agent, (await request.json()) as Partial<AgentInput>)
    db.agents[index] = updated
    return HttpResponse.json({ data: updated })
  }),
  http.get(apiUrl('/agents/{agent}/workload'), ({ params }) => {
    const agent = db.agents.find((row) => row.id === params.agent)
    if (!agent) return notFound()
    return HttpResponse.json({
      data: {
        active_ticket_count: agent.active_ticket_count,
        capacity: agent.capacity,
        load: agent.active_ticket_count / agent.capacity,
        by_priority: { P1: 0, P2: agent.active_ticket_count, P3: 0, P4: 0 },
      },
    })
  }),
  http.get(apiUrl('/agents/{agent}/shifts'), ({ params }) =>
    HttpResponse.json({ data: db.agentShifts[String(params.agent)] ?? [] }),
  ),
  http.put(apiUrl('/agents/{agent}/shifts'), async ({ params, request }) => {
    const body = (await request.json()) as ShiftInput
    const rows: AgentShiftResource[] = body.shifts.map((shift) => ({
      id: nextId(10),
      weekday: shift.weekday ?? null,
      date: shift.date ?? null,
      starts_at: shift.starts_at,
      ends_at: shift.ends_at,
      is_off: shift.is_off,
    }))
    db.agentShifts[String(params.agent)] = rows
    return HttpResponse.json({ data: rows })
  }),
  http.get(apiUrl('/skills'), ({ request }) => directoryList(request, db.skills)),
  http.post(apiUrl('/skills'), async ({ request }) => {
    const body = (await request.json()) as SkillInput
    const skill: SkillResource = { id: nextId(6), ...body, description: body.description ?? null }
    db.skills.push(skill)
    return HttpResponse.json({ data: skill }, { status: 201 })
  }),
  http.patch(apiUrl('/skills/{skill}'), async ({ params, request }) => {
    const index = db.skills.findIndex((row) => row.id === params.skill)
    if (index < 0) return notFound()
    const current = db.skills[index] as SkillResource
    const body = (await request.json()) as SkillInput
    const updated = { ...current, ...body }
    db.skills[index] = updated
    return HttpResponse.json({ data: updated })
  }),
  http.delete(apiUrl('/skills/{skill}'), ({ params }) => {
    db.skills = db.skills.filter((row) => row.id !== params.skill)
    return new HttpResponse(null, { status: 204 })
  }),
  http.get(apiUrl('/teams'), ({ request }) => directoryList(request, db.teams)),
  http.post(apiUrl('/teams'), async ({ request }) => {
    const body = (await request.json()) as TeamInput
    const team: TeamResource = { id: nextId(9), ...body, description: body.description ?? null, members: [] }
    db.teams.push(team)
    return HttpResponse.json({ data: team }, { status: 201 })
  }),
  http.patch(apiUrl('/teams/{team}'), async ({ params, request }) => {
    const team = db.teams.find((row) => row.id === params.team)
    if (!team) return notFound()
    Object.assign(team, (await request.json()) as TeamInput)
    return HttpResponse.json({ data: team })
  }),
  http.put(apiUrl('/teams/{team}/members'), async ({ params, request }) => {
    const team = db.teams.find((row) => row.id === params.team)
    if (!team) return notFound()
    const body = (await request.json()) as { agent_ids: string[] }
    team.members = db.agents
      .filter((agent) => body.agent_ids.includes(agent.id))
      .map((agent) => ({
        id: agent.id,
        user_id: agent.user.id,
        name: agent.user.name,
        email: agent.user.email,
        availability: agent.availability,
        capacity: agent.capacity,
      }))
    return HttpResponse.json({ data: team })
  }),
  http.post(apiUrl('/categories'), async ({ request }) => {
    const body = (await request.json()) as CategoryInput
    const team = db.teams.find((row) => row.id === body.default_team_id)
    const category = {
      id: nextId(4),
      name: body.name,
      default_team: team ? { id: team.id, name: team.name } : null,
      required_skills: db.skills.filter((row) => body.skill_ids?.includes(row.id)),
      is_active: body.is_active ?? true,
      sort_order: body.sort_order ?? 0,
    }
    db.categories.push(category)
    return HttpResponse.json({ data: category }, { status: 201 })
  }),
  http.patch(apiUrl('/categories/{category}'), async ({ params, request }) => {
    const category = db.categories.find((row) => row.id === params.category)
    if (!category) return notFound()
    const body = (await request.json()) as CategoryInput
    category.name = body.name
    if (body.skill_ids) category.required_skills = db.skills.filter((row) => body.skill_ids?.includes(row.id))
    return HttpResponse.json({ data: category })
  }),
]

export { AGENT_FIXTURES, SKILL_FIXTURES, TEAM_FIXTURES } from './data'

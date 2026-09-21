import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import { type ApiListQuery, defineListSchema } from '@/lib/list-params'

export const directoryListSchema = defineListSchema({
  sortFields: ['name', 'created_at'] as const,
  defaultSort: 'name',
  filters: {},
})
export const agentListSchema = defineListSchema({
  sortFields: ['created_at', 'capacity', 'availability'] as const,
  defaultSort: 'created_at',
  filters: {},
})

export type Agent = components['schemas']['AgentResource']
export type AgentInput = components['schemas']['SaveAgentRequest']
export type AgentUpdateInput = components['schemas']['UpdateAgentRequest']
export type AgentAvailability = components['schemas']['AgentAvailability']
export type AgentShift = components['schemas']['AgentShiftResource']
export type AgentShiftInput = components['schemas']['ReplaceAgentShiftsRequest']
export type Skill = components['schemas']['SkillResource']
export type SkillInput = components['schemas']['SaveSkillRequest']
export type Team = components['schemas']['TeamResource']
export type TeamInput = components['schemas']['SaveTeamRequest']
export type Category = components['schemas']['CategoryResource']
export type CategoryInput = components['schemas']['SaveCategoryRequest']

type AgentIndex = operations['agents.index']
type SkillIndex = operations['skills.index']
type TeamIndex = operations['teams.index']
type AgentListQuery = NonNullable<AgentIndex['parameters']['query']>
type SkillListQuery = NonNullable<SkillIndex['parameters']['query']>
type TeamListQuery = NonNullable<TeamIndex['parameters']['query']>

export const agentQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.agents.list(tenantId, query),
      queryFn: () => unwrapBody(api().GET('/agents', { params: { query: query as AgentListQuery } })),
      placeholderData: keepPreviousData,
    }),
  detail: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.agents.detail(tenantId, id),
      queryFn: () => unwrap(api().GET('/agents/{agent}', { params: { path: { agent: id } } })),
    }),
  workload: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.agents.workload(tenantId, id),
      queryFn: () => unwrap(api().GET('/agents/{agent}/workload', { params: { path: { agent: id } } })),
    }),
  shifts: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.agents.shifts(tenantId, id),
      queryFn: () => unwrap(api().GET('/agents/{agent}/shifts', { params: { path: { agent: id } } })),
    }),
  availableUsers: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.agents.availableUsers(tenantId),
      queryFn: () => unwrap(api().GET('/agents/available-users')),
    }),
}

export const skillQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.skills.list(tenantId, query),
      queryFn: () => unwrapBody(api().GET('/skills', { params: { query: query as SkillListQuery } })),
      placeholderData: keepPreviousData,
    }),
}

export const teamQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.teams.list(tenantId, query),
      queryFn: () => unwrapBody(api().GET('/teams', { params: { query: query as TeamListQuery } })),
      placeholderData: keepPreviousData,
    }),
}

export const createAgent = (input: AgentInput) => unwrap(api().POST('/agents', { body: input }))
export const updateAgent = (id: string, input: AgentUpdateInput) =>
  unwrap(api().PATCH('/agents/{agent}', { params: { path: { agent: id } }, body: input }))
export const updateAvailability = (id: string, availability: AgentAvailability) =>
  updateAgent(id, { availability })
export const replaceAgentShifts = (id: string, input: AgentShiftInput) =>
  unwrap(api().PUT('/agents/{agent}/shifts', { params: { path: { agent: id } }, body: input }))

export const createSkill = (input: SkillInput) => unwrap(api().POST('/skills', { body: input }))
export const updateSkill = (id: string, input: SkillInput) =>
  unwrap(api().PATCH('/skills/{skill}', { params: { path: { skill: id } }, body: input }))
export const deleteSkill = (id: string) =>
  unwrapBody(api().DELETE('/skills/{skill}', { params: { path: { skill: id } } }))

export const createTeam = (input: TeamInput) => unwrap(api().POST('/teams', { body: input }))
export const updateTeam = (id: string, input: TeamInput) =>
  unwrap(api().PATCH('/teams/{team}', { params: { path: { team: id } }, body: input }))
export const replaceTeamMembers = (id: string, agentIds: string[]) =>
  unwrap(
    api().PUT('/teams/{team}/members', { params: { path: { team: id } }, body: { agent_ids: agentIds } }),
  )

export const categoryQueries = {
  all: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.categories.all(tenantId),
      queryFn: () => unwrap(api().GET('/categories')),
      staleTime: 5 * 60_000,
    }),
}

export const createCategory = (input: CategoryInput) => unwrap(api().POST('/categories', { body: input }))
export const updateCategory = (id: string, input: CategoryInput) =>
  unwrap(api().PATCH('/categories/{category}', { params: { path: { category: id } }, body: input }))

import { useQuery } from '@tanstack/react-query'
import { useCan, useSession } from '@/lib/auth'
import { type Agent, agentQueries, type Team, teamQueries } from './agent-queries'

const WHOLE_DIRECTORY = { page: 1, per_page: 100 } as const
const NONE_AGENTS: Agent[] = []
const NONE_TEAMS: Team[] = []

/**
 * Names for the Agent and Team ids a ticket carries (`assigned_agent_id`, `team_id`; the assignment
 * preview's `excluded[].agent_id`). The ticket resource has no assignee include, so the directory is
 * read once (it is cached and shared) and looked up. Without `agents.view`, or for an id beyond the
 * first hundred, the lookup answers `undefined` and the caller shows its "unknown" wording — never the id.
 */
export function useDirectoryNames(enabled = true) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const allowed = useCan('agents.view') && enabled && tenantId !== ''
  const agents = useQuery({
    ...agentQueries.list(tenantId, { ...WHOLE_DIRECTORY, sort: 'created_at' }),
    enabled: allowed,
    staleTime: 60_000,
  })
  const teams = useQuery({
    ...teamQueries.list(tenantId, { ...WHOLE_DIRECTORY, sort: 'name' }),
    enabled: allowed,
    staleTime: 60_000,
  })
  return {
    isLoading: allowed && (agents.isPending || teams.isPending),
    /** The first hundred Agents and Teams (empty without `agents.view`), for pickers and filters. */
    agents: agents.data?.data ?? NONE_AGENTS,
    teams: teams.data?.data ?? NONE_TEAMS,
    agentName: (id: string | null | undefined): string | undefined =>
      id ? agents.data?.data.find((agent) => agent.id === id)?.user.name : undefined,
    teamName: (id: string | null | undefined): string | undefined =>
      id ? teams.data?.data.find((team) => team.id === id)?.name : undefined,
  }
}

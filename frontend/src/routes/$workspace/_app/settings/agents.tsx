import { createFileRoute } from '@tanstack/react-router'
import { AgentSettings, agentListSchema } from '@/features/agents'

export const Route = createFileRoute('/$workspace/_app/settings/agents')({
  validateSearch: agentListSchema.searchSchema,
  component: AgentSettings,
})

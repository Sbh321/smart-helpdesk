import { createFileRoute } from '@tanstack/react-router'
import { directoryListSchema, TeamSettings } from '@/features/agents'

export const Route = createFileRoute('/$workspace/_app/settings/teams')({
  validateSearch: directoryListSchema.searchSchema,
  component: TeamSettings,
})

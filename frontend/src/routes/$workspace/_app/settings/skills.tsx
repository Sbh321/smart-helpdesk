import { createFileRoute } from '@tanstack/react-router'
import { directoryListSchema, SkillSettings } from '@/features/agents'

export const Route = createFileRoute('/$workspace/_app/settings/skills')({
  validateSearch: directoryListSchema.searchSchema,
  component: SkillSettings,
})

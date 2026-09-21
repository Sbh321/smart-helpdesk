import { createFileRoute } from '@tanstack/react-router'
import { AutomationSettings } from '@/features/settings'

export const Route = createFileRoute('/$workspace/_app/settings/automation')({
  component: AutomationSettings,
})

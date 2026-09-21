import { createFileRoute } from '@tanstack/react-router'
import { ApiClientsSettings } from '@/features/integrations'

export const Route = createFileRoute('/$workspace/_app/settings/api-clients')({
  component: ApiClientsSettings,
})

import { createFileRoute } from '@tanstack/react-router'
import { WebhooksSettings } from '@/features/integrations'

export const Route = createFileRoute('/$workspace/_app/settings/webhooks')({
  component: WebhooksSettings,
})

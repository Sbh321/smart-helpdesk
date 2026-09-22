import { createFileRoute } from '@tanstack/react-router'
import { EmailSettings, inboundListSchema } from '@/features/mail'

/** Settings → Email (M3-18); the inbound log's result filter is the URL (M3-19). */
export const Route = createFileRoute('/$workspace/_app/settings/email')({
  validateSearch: inboundListSchema.searchSchema,
  component: EmailSettings,
})

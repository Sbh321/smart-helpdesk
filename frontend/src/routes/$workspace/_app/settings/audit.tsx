import { createFileRoute } from '@tanstack/react-router'
import { AuditSettings, auditListSchema } from '@/features/audit'

/** Settings → Audit log (roadmap M3-03); its filters are the URL. */
export const Route = createFileRoute('/$workspace/_app/settings/audit')({
  validateSearch: auditListSchema.searchSchema,
  component: AuditSettings,
})

import { createFileRoute } from '@tanstack/react-router'
import { tenantListSchema, WorkspacesScreen } from '@/features/platform'

/** Every workspace, with its status and subscription (ADR-0025, M6-06). */
export const Route = createFileRoute('/_platform/platform/tenants/')({
  validateSearch: tenantListSchema.searchSchema,
  component: WorkspacesScreen,
})

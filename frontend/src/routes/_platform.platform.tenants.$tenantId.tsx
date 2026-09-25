import { createFileRoute } from '@tanstack/react-router'
import { WorkspaceDetailScreen } from '@/features/platform'

/** One workspace: details, status, subscription and payments (ADR-0025, M6-06). */
export const Route = createFileRoute('/_platform/platform/tenants/$tenantId')({
  component: function WorkspacePage() {
    const { tenantId } = Route.useParams()
    return <WorkspaceDetailScreen tenantId={tenantId} />
  },
})

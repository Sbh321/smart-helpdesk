import { createFileRoute, notFound } from '@tanstack/react-router'
import { isWorkspaceSlug } from '@/lib/auth'

/**
 * Everything inside a workspace hangs off this route. The segment is presentation only: it is compared
 * with `tenant.slug` from `/v1/me` in the `_app` guard and is never sent to the API as a tenant selector
 * ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)).
 */
export const Route = createFileRoute('/$workspace')({
  beforeLoad: ({ params }) => {
    if (!isWorkspaceSlug(params.workspace)) {
      throw notFound()
    }
  },
})

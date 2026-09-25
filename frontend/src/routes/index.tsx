import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { ensureSession, WorkspaceEntry, WorkspaceFinderForm } from '@/features/auth'
import { workspaceHref } from '@/lib/auth'

const searchSchema = z.object({
  /** `?find=true`: "Email me my workspace" instead of the workspace field (M5-03). */
  find: z.boolean().optional(),
})

/**
 * `/` has no workspace, so it asks for one ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md):
 * the workspace is a path segment on `app.<domain>`). A signed-in visitor goes straight to their own.
 */
export const Route = createFileRoute('/')({
  validateSearch: searchSchema,
  beforeLoad: async ({ context }) => {
    // The admin host serves the same build (appMode "platform"); its start page is the platform console.
    if (context.config.appMode === 'platform') {
      throw redirect({ to: '/platform/tenants', replace: true })
    }
    const session = await ensureSession(context.queryClient)
    if (session) {
      throw redirect({ href: workspaceHref(session), replace: true })
    }
  },
  component: WorkspaceEntryPage,
})

function WorkspaceEntryPage() {
  const { find } = Route.useSearch()

  return find ? (
    <AuthLayout title={copy.workspaceFinder.heading} description={copy.workspaceFinder.body}>
      <WorkspaceFinderForm />
    </AuthLayout>
  ) : (
    <AuthLayout title={copy.workspaceEntry.heading} description={copy.workspaceEntry.body}>
      <WorkspaceEntry />
    </AuthLayout>
  )
}

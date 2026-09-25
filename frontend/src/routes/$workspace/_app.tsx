import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { AppShell } from '@/components/layout/app-shell'
import { ErrorState } from '@/components/shared/error-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { AvailabilityControl } from '@/features/agents'
import { ensureSession } from '@/features/auth'
import { SubscriptionBanner } from '@/features/billing'
import { NotificationBell } from '@/features/notifications'
import { guardWorkspaceRoute, REDIRECT_PARAM } from '@/lib/auth'
import { RealtimeProvider } from '@/lib/realtime'

/**
 * The authenticated shell. The guard runs before anything renders: no session sends the visitor to the
 * workspace's sign-in page with `?redirect=`, and a URL naming another workspace than the session is
 * rewritten to the session's own ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)).
 */
export const Route = createFileRoute('/$workspace/_app')({
  beforeLoad: async ({ context, params, location }) => {
    const session = await ensureSession(context.queryClient)
    const decision = guardWorkspaceRoute({
      session,
      workspace: params.workspace,
      href: location.href,
    })
    if (decision.kind === 'sign-in') {
      throw redirect({
        to: '/$workspace/login',
        params: { workspace: decision.workspace },
        search: { [REDIRECT_PARAM]: decision.redirect },
        replace: true,
      })
    }
    if (decision.kind === 'switch-workspace') {
      throw redirect({ href: decision.href, replace: true })
    }
  },
  component: AppLayout,
  errorComponent: ({ error, reset }) => <ErrorState error={error} onRetry={reset} />,
  notFoundComponent: () => <NotFoundState />,
})

function AppLayout() {
  const { workspace } = Route.useParams()
  const { config } = Route.useRouteContext()
  // Live updates (M3-16) for everything inside the shell; off unless config.json and the workspace say so.
  return (
    <RealtimeProvider config={config}>
      <AppShell
        workspace={workspace}
        topbarActions={<AvailabilityControl />}
        topbarNotifications={<NotificationBell workspace={workspace} />}
        banner={<SubscriptionBanner workspace={workspace} />}
      >
        <Outlet />
      </AppShell>
    </RealtimeProvider>
  )
}

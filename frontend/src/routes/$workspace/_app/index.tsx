import { createFileRoute } from '@tanstack/react-router'
import { DashboardScreen, DEFAULT_PERIOD, dashboardSearchSchema } from '@/features/reports'
import { GetStartedPanel } from '@/features/settings'
import { TicketsRightNow } from '@/features/tickets'

/**
 * Landing page after sign-in: the dashboard (roadmap M3-01); `?period=` selects the period. The route
 * composes the live queue counts of the tickets feature and the settings feature's Get started panel into
 * it (M4-08), since reports may not import either.
 */
export const Route = createFileRoute('/$workspace/_app/')({
  validateSearch: dashboardSearchSchema,
  component: DashboardPage,
})

function DashboardPage() {
  const { workspace } = Route.useParams()
  const { period } = Route.useSearch()
  return (
    <DashboardScreen
      workspace={workspace}
      period={period ?? DEFAULT_PERIOD}
      intro={<GetStartedPanel workspace={workspace} />}
      now={<TicketsRightNow workspace={workspace} />}
    />
  )
}

import { createFileRoute } from '@tanstack/react-router'
import { DashboardScreen, DEFAULT_PERIOD, dashboardSearchSchema } from '@/features/reports'
import { TicketsRightNow } from '@/features/tickets'

/**
 * Landing page after sign-in: the dashboard (roadmap M3-01); `?period=` selects the period. The route
 * composes the live queue counts of the tickets feature into it (M4-08), since reports may not import
 * tickets.
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
      now={<TicketsRightNow workspace={workspace} />}
    />
  )
}

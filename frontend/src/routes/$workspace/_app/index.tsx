import { createFileRoute } from '@tanstack/react-router'
import { DashboardScreen, DEFAULT_PERIOD, dashboardSearchSchema } from '@/features/reports'

/** Landing page after sign-in: the dashboard (roadmap M3-01); `?period=` selects the period. */
export const Route = createFileRoute('/$workspace/_app/')({
  validateSearch: dashboardSearchSchema,
  component: DashboardPage,
})

function DashboardPage() {
  const { workspace } = Route.useParams()
  const { period } = Route.useSearch()
  return <DashboardScreen workspace={workspace} period={period ?? DEFAULT_PERIOD} />
}

import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ReportsScreen } from '@/features/reports'

/** The report catalogue (roadmap M3-20). */
export const Route = createFileRoute('/$workspace/_app/reports/')({
  component: ReportsPage,
  staticData: { crumb: copy.reports.title },
})

function ReportsPage() {
  const { workspace } = Route.useParams()
  return <ReportsScreen workspace={workspace} />
}

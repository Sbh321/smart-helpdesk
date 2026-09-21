import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ReportScreen, reportSearchSchema } from '@/features/reports'

/** One report; every parameter is a search param, so the URL can be shared (roadmap M3-20). */
export const Route = createFileRoute('/$workspace/_app/reports/$reportKey')({
  validateSearch: reportSearchSchema,
  component: ReportPage,
  staticData: { crumb: copy.reports.title },
})

function ReportPage() {
  const { workspace, reportKey } = Route.useParams()
  return <ReportScreen workspace={workspace} reportKey={reportKey} />
}

import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { EntityRecordScreen } from '@/features/reports'

/** A Team's read-only page: Overview and History (roadmap M3-21). */
export const Route = createFileRoute('/$workspace/_app/teams/$teamId')({
  component: TeamPage,
  staticData: { crumb: copy.entity360.detailTitles.teams },
})

function TeamPage() {
  const { workspace, teamId } = Route.useParams()
  return <EntityRecordScreen entity="teams" id={teamId} workspace={workspace} />
}

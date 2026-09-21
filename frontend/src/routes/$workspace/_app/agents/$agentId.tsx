import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { EntityRecordScreen } from '@/features/reports'

/** An Agent's read-only page: Overview and History (roadmap M3-21). */
export const Route = createFileRoute('/$workspace/_app/agents/$agentId')({
  component: AgentPage,
  staticData: { crumb: copy.entity360.detailTitles.agents },
})

function AgentPage() {
  const { workspace, agentId } = Route.useParams()
  return <EntityRecordScreen entity="agents" id={agentId} workspace={workspace} />
}

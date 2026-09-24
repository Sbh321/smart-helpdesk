import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { OrganizationScreen } from '@/features/contacts'
import { useEntityTabs } from '@/features/reports'
import { recordSearchSchema } from '@/lib/record-view'

export const Route = createFileRoute('/$workspace/_app/organizations/$organizationId')({
  validateSearch: recordSearchSchema,
  component: OrganizationPage,
  staticData: { crumb: copy.organizations.editTitle },
})

function OrganizationPage() {
  const { workspace, organizationId } = Route.useParams()
  const tabs = useEntityTabs('organizations', organizationId, workspace)
  return <OrganizationScreen workspace={workspace} organizationId={organizationId} tabs={tabs} />
}

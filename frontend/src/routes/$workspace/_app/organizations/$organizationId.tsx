import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { OrganizationScreen } from '@/features/contacts'

export const Route = createFileRoute('/$workspace/_app/organizations/$organizationId')({
  component: OrganizationPage,
  staticData: { crumb: copy.organizations.editTitle },
})

function OrganizationPage() {
  const { workspace, organizationId } = Route.useParams()
  return <OrganizationScreen workspace={workspace} organizationId={organizationId} />
}

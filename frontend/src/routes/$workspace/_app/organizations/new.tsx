import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { NewOrganizationScreen } from '@/features/contacts'

export const Route = createFileRoute('/$workspace/_app/organizations/new')({
  component: NewOrganizationPage,
  staticData: { crumb: copy.organizations.newTitle },
})

function NewOrganizationPage() {
  const { workspace } = Route.useParams()
  return <NewOrganizationScreen workspace={workspace} />
}

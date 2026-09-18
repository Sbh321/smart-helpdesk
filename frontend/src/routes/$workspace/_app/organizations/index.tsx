import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { OrganizationsScreen, organizationListSchema } from '@/features/contacts'

export const Route = createFileRoute('/$workspace/_app/organizations/')({
  validateSearch: organizationListSchema.searchSchema,
  component: OrganizationsPage,
  staticData: { crumb: copy.organizations.title },
})

function OrganizationsPage() {
  const { workspace } = Route.useParams()
  return <OrganizationsScreen workspace={workspace} />
}

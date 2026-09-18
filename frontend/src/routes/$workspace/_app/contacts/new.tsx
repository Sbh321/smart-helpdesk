import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { NewContactScreen } from '@/features/contacts'

export const Route = createFileRoute('/$workspace/_app/contacts/new')({
  component: NewContactPage,
  staticData: { crumb: copy.contacts.newTitle },
})

function NewContactPage() {
  const { workspace } = Route.useParams()
  return <NewContactScreen workspace={workspace} />
}

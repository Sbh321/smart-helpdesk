import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ContactScreen } from '@/features/contacts'

export const Route = createFileRoute('/$workspace/_app/contacts/$contactId')({
  component: ContactPage,
  staticData: { crumb: copy.contacts.editTitle },
})

function ContactPage() {
  const { workspace, contactId } = Route.useParams()
  return <ContactScreen workspace={workspace} contactId={contactId} />
}

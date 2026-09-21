import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ContactScreen } from '@/features/contacts'
import { useEntityTabs } from '@/features/reports'

export const Route = createFileRoute('/$workspace/_app/contacts/$contactId')({
  component: ContactPage,
  staticData: { crumb: copy.contacts.editTitle },
})

function ContactPage() {
  const { workspace, contactId } = Route.useParams()
  const tabs = useEntityTabs('contacts', contactId, workspace)
  return <ContactScreen workspace={workspace} contactId={contactId} tabs={tabs} />
}

import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ContactScreen } from '@/features/contacts'
import { useEntityTabs } from '@/features/reports'
import { recordSearchSchema } from '@/lib/record-view'

export const Route = createFileRoute('/$workspace/_app/contacts/$contactId')({
  validateSearch: recordSearchSchema,
  component: ContactPage,
  staticData: { crumb: copy.contacts.editTitle },
})

function ContactPage() {
  const { workspace, contactId } = Route.useParams()
  const tabs = useEntityTabs('contacts', contactId, workspace)
  return <ContactScreen workspace={workspace} contactId={contactId} tabs={tabs} />
}

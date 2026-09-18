import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { ContactsScreen, contactListSchema } from '@/features/contacts'

/**
 * The contact list. Page, sort, search and filters are search params validated by the list schema, so
 * the URL alone restores the list (roadmap M1-14, M1-15).
 */
export const Route = createFileRoute('/$workspace/_app/contacts/')({
  validateSearch: contactListSchema.searchSchema,
  component: ContactsPage,
  staticData: { crumb: copy.contacts.title },
})

function ContactsPage() {
  const { workspace } = Route.useParams()
  return <ContactsScreen workspace={workspace} />
}

import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { NewTicketScreen } from '@/features/tickets'

export const Route = createFileRoute('/$workspace/_app/tickets/new')({
  component: NewTicketPage,
  staticData: { crumb: copy.tickets.create.title },
})

function NewTicketPage() {
  const { workspace } = Route.useParams()
  return <NewTicketScreen workspace={workspace} />
}

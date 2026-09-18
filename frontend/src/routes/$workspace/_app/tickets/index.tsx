import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { TicketsScreen, ticketListSchema } from '@/features/tickets'

/** The ticket list; its state is the URL (roadmap M1-14, M1-17). */
export const Route = createFileRoute('/$workspace/_app/tickets/')({
  validateSearch: ticketListSchema.searchSchema,
  component: TicketsPage,
  staticData: { crumb: copy.tickets.title },
})

function TicketsPage() {
  const { workspace } = Route.useParams()
  return <TicketsScreen workspace={workspace} />
}

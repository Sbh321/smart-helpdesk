import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { TicketScreen } from '@/features/tickets'

/** Ticket detail, lifecycle actions and history (roadmap M2-06). */
export const Route = createFileRoute('/$workspace/_app/tickets/$ticketId')({
  component: TicketPage,
  staticData: { crumb: copy.tickets.detailTitle },
})

function TicketPage() {
  const { workspace, ticketId } = Route.useParams()
  return <TicketScreen workspace={workspace} ticketId={ticketId} />
}

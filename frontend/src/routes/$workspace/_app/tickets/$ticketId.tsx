import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { TicketScreen } from '@/features/tickets'

/** Ticket detail placeholder: fields and history (roadmap M1-17); the full page is milestone 2. */
export const Route = createFileRoute('/$workspace/_app/tickets/$ticketId')({
  component: TicketPage,
  staticData: { crumb: copy.tickets.detailTitle },
})

function TicketPage() {
  const { workspace, ticketId } = Route.useParams()
  return <TicketScreen workspace={workspace} ticketId={ticketId} />
}

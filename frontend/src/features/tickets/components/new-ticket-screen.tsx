import { useNavigate } from '@tanstack/react-router'
import { BackLink } from '@/components/shared/back-link'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { PageHeader } from '@/components/shared/page-header'
import { copy } from '@/copy/en'
import { useCan } from '@/lib/auth'
import { CreateTicketDialog } from './create-ticket-dialog'

export function NewTicketScreen({ workspace }: { workspace: string }) {
  const allowed = useCan('tickets.create')
  const navigate = useNavigate()
  return (
    <>
      <PageHeader
        title={copy.tickets.create.title}
        description={copy.tickets.create.description}
        eyebrow={<BackLink workspace={workspace} to="/$workspace/tickets" label={copy.tickets.detail.back} />}
      />
      {allowed ? (
        <CreateTicketDialog
          page
          onCreated={(ticket) =>
            void navigate({ to: '/$workspace/tickets/$ticketId', params: { workspace, ticketId: ticket.id } })
          }
        />
      ) : (
        <ForbiddenState />
      )}
    </>
  )
}

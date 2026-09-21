import { createFileRoute } from '@tanstack/react-router'
import { TicketSettings } from '@/features/settings'

export const Route = createFileRoute('/$workspace/_app/settings/tickets')({ component: TicketSettings })

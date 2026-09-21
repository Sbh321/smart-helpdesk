import { createFileRoute } from '@tanstack/react-router'
import { CalendarSettings } from '@/features/sla'

export const Route = createFileRoute('/$workspace/_app/settings/calendars')({ component: CalendarSettings })

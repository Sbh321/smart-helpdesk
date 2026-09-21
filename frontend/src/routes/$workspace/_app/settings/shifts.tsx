import { createFileRoute } from '@tanstack/react-router'
import { ShiftSettings } from '@/features/agents'

export const Route = createFileRoute('/$workspace/_app/settings/shifts')({ component: ShiftSettings })

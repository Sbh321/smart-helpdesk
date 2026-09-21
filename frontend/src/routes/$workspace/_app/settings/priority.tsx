import { createFileRoute } from '@tanstack/react-router'
import { PrioritySettings } from '@/features/automation'

export const Route = createFileRoute('/$workspace/_app/settings/priority')({ component: PrioritySettings })

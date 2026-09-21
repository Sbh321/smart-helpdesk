import { createFileRoute } from '@tanstack/react-router'
import { PolicySettings } from '@/features/sla'

export const Route = createFileRoute('/$workspace/_app/settings/sla')({ component: PolicySettings })

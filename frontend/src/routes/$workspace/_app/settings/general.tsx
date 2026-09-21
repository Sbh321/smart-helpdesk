import { createFileRoute } from '@tanstack/react-router'
import { GeneralSettings } from '@/features/settings'

export const Route = createFileRoute('/$workspace/_app/settings/general')({ component: GeneralSettings })

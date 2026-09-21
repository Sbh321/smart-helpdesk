import { createFileRoute } from '@tanstack/react-router'
import { CategorySettings } from '@/features/agents'

export const Route = createFileRoute('/$workspace/_app/settings/categories')({ component: CategorySettings })

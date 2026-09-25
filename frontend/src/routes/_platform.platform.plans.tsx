import { createFileRoute } from '@tanstack/react-router'
import { PlansScreen } from '@/features/platform'

export const Route = createFileRoute('/_platform/platform/plans')({
  component: PlansScreen,
})

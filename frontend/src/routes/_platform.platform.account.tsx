import { createFileRoute } from '@tanstack/react-router'
import { AccountScreen } from '@/features/platform'

export const Route = createFileRoute('/_platform/platform/account')({
  component: AccountScreen,
})

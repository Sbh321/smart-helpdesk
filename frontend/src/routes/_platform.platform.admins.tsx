import { createFileRoute } from '@tanstack/react-router'
import { AdminsScreen } from '@/features/platform'

export const Route = createFileRoute('/_platform/platform/admins')({
  component: AdminsScreen,
})

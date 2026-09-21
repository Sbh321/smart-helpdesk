import { createFileRoute } from '@tanstack/react-router'
import { RolesSettings } from '@/features/users'

export const Route = createFileRoute('/$workspace/_app/settings/roles')({
  component: RolesSettings,
})

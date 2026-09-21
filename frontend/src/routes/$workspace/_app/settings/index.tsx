import { createFileRoute, Navigate } from '@tanstack/react-router'
import { useCan } from '@/lib/auth'

export const Route = createFileRoute('/$workspace/_app/settings/')({ component: SettingsIndex })

function SettingsIndex() {
  const { workspace } = Route.useParams()
  const canViewAgents = useCan('agents.view')
  const canViewTickets = useCan('tickets.view')
  const canManageSettings = useCan('settings.manage')
  const canManageUsers = useCan('users.manage')
  const canManageRoles = useCan('roles.manage')
  const to = canManageSettings
    ? '/$workspace/settings/general'
    : canViewAgents
      ? '/$workspace/settings/agents'
      : canViewTickets
        ? '/$workspace/settings/categories'
        : canManageUsers
          ? '/$workspace/settings/users'
          : canManageRoles
            ? '/$workspace/settings/roles'
            : null
  if (to === null) return null
  return <Navigate to={to} params={{ workspace }} replace />
}

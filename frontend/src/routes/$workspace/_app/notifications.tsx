import { createFileRoute } from '@tanstack/react-router'
import { NotificationsScreen } from '@/features/notifications'

export const Route = createFileRoute('/$workspace/_app/notifications')({ component: NotificationsPage })

function NotificationsPage() {
  const { workspace } = Route.useParams()
  return <NotificationsScreen workspace={workspace} />
}

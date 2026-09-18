import { createFileRoute } from '@tanstack/react-router'
import { LayoutDashboardIcon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { PageHeader } from '@/components/shared/page-header'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'

/** Landing page after sign-in. The real dashboard arrives in roadmap M3-01. */
export const Route = createFileRoute('/$workspace/_app/')({
  component: DashboardPage,
})

function DashboardPage() {
  const { session } = useSession()

  return (
    <>
      <PageHeader
        title={copy.dashboard.title}
        description={session ? `${copy.dashboard.description} — ${session.tenant.name}` : undefined}
      />
      <EmptyState
        icon={LayoutDashboardIcon}
        title={copy.dashboard.placeholderTitle}
        description={copy.dashboard.placeholderBody}
      />
    </>
  )
}

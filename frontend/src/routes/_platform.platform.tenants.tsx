import { createFileRoute } from '@tanstack/react-router'
import { Building2Icon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { PageHeader } from '@/components/shared/page-header'
import { copy } from '@/copy/en'

/** Minimal tenants list; the platform API and its guard arrive in roadmap M1-07. */
export const Route = createFileRoute('/_platform/platform/tenants')({
  component: TenantsPage,
})

function TenantsPage() {
  return (
    <>
      <PageHeader title={copy.platform.title} description={copy.platform.description} />
      <EmptyState
        icon={Building2Icon}
        title={copy.platform.placeholderTitle}
        description={copy.platform.placeholderBody}
      />
    </>
  )
}

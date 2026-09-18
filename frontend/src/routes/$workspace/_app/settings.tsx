import { createFileRoute } from '@tanstack/react-router'
import { SettingsIcon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { PageHeader } from '@/components/shared/page-header'
import { copy } from '@/copy/en'
import { useCan } from '@/lib/auth'

/** Placeholder until workspace settings land (roadmap M2-01). */
export const Route = createFileRoute('/$workspace/_app/settings')({
  component: SettingsPage,
  staticData: { crumb: copy.settings.title },
})

function SettingsPage() {
  const allowed = useCan('settings.manage')

  return (
    <>
      <PageHeader title={copy.settings.title} description={copy.settings.description} />
      {allowed ? (
        <EmptyState
          icon={SettingsIcon}
          title={copy.settings.placeholderTitle}
          description={copy.settings.placeholderBody}
        />
      ) : (
        <ForbiddenState />
      )}
    </>
  )
}

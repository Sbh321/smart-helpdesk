import type { ReactNode } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { Skeleton } from '@/components/ui/skeleton'
import { type SettingsSectionKey, useSettingsSection } from '../api/settings-queries'
import { readSection, type SectionValues, type sectionValues } from '../schemas'

export interface LoadedSection<K extends keyof typeof sectionValues> {
  tenantId: string
  values: SectionValues<K>
  defaults: SectionValues<K>
}

/**
 * Loads one settings section behind `settings.manage` and renders its loading, error and forbidden
 * states; `children` gets the typed values once they are there. With a `title` it is a page section
 * with a heading; without one the caller provides the frame (cards inside other pages).
 */
export function SettingsSectionScreen<K extends SettingsSectionKey & keyof typeof sectionValues>({
  section,
  title,
  intro,
  hideWhenForbidden = false,
  children,
}: {
  section: K
  title: string
  intro?: string
  /** Cards embedded in a page other roles can see simply do not render without the permission. */
  hideWhenForbidden?: boolean
  children: (loaded: LoadedSection<K>) => ReactNode
}) {
  const { allowed, tenantId, query } = useSettingsSection(section)
  if (!allowed) return hideWhenForbidden ? null : <ForbiddenState />
  const headingId = `settings-${section.replace('.', '-')}-heading`

  return (
    <section className="space-y-4" aria-labelledby={headingId}>
      <h2 id={headingId} className="text-lg font-semibold">
        {title}
      </h2>
      {intro ? <p className="max-w-2xl text-sm text-muted-foreground">{intro}</p> : null}
      {query.isPending ? (
        <Skeleton className="h-32 w-full max-w-2xl" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : (
        children({ tenantId, ...readSection(section, query.data) })
      )}
    </section>
  )
}

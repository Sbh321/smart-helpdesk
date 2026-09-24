import type { ReactNode } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { SettingsPage } from '@/components/shared/settings-page'
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
 * states; `children` gets the typed values once they are there. A page is framed by `SettingsPage`
 * (M4-12); a card embedded in another page (`hideWhenForbidden`) is a bordered section with an h3.
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
  const body = query.isPending ? (
    <Skeleton className="h-32 w-full max-w-2xl" />
  ) : query.isError ? (
    <ErrorState error={query.error} onRetry={() => void query.refetch()} />
  ) : (
    children({ tenantId, ...readSection(section, query.data) })
  )

  // A card inside another settings page (SLA defaults on SLA policies): one level below its header.
  if (hideWhenForbidden) {
    return (
      // No frame of its own: the card's content already sits in a bordered box.
      <section className="space-y-4" aria-labelledby={headingId}>
        <div>
          <h3 id={headingId} className="text-base font-semibold">
            {title}
          </h3>
          {intro ? <p className="max-w-2xl text-sm text-muted-foreground">{intro}</p> : null}
        </div>
        {body}
      </section>
    )
  }
  return (
    <SettingsPage title={title} description={intro}>
      {body}
    </SettingsPage>
  )
}

import type { ReactNode } from 'react'
import { useId } from 'react'
import { copy } from '@/copy/en'

/**
 * One settings section (roadmap M4-12, docs/06-design-system/page-patterns.md §Settings page): the
 * section's name and what it controls, its primary action on the right, the content, and destructive
 * actions apart at the end under "Danger zone". Every page under `/settings` uses it, so the header,
 * the spacing and the place of a dangerous action are the same everywhere.
 */
export function SettingsPage({
  title,
  description,
  actions,
  danger,
  children,
}: {
  title: string
  description?: ReactNode
  /** The section's primary action, e.g. "Add webhook". */
  actions?: ReactNode
  /** Actions that delete or revoke, each behind a ConfirmDialog. */
  danger?: ReactNode
  children: ReactNode
}) {
  const headingId = useId()
  const dangerId = useId()
  return (
    <section aria-labelledby={headingId} className="flex min-w-0 flex-col gap-6" data-slot="settings-page">
      {/* Title and description take the room; the action keeps its place on the right from `sm` up. */}
      <header className="flex flex-col gap-3 border-border border-b pb-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 max-w-2xl flex-1">
          <h2 id={headingId} className="text-lg font-semibold">
            {title}
          </h2>
          {description ? <p className="mt-1 text-sm text-muted-foreground">{description}</p> : null}
        </div>
        {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div> : null}
      </header>
      {children}
      {danger ? (
        <section
          aria-labelledby={dangerId}
          className="flex flex-col gap-3 rounded-lg border border-destructive/40 p-4 bg-surface"
        >
          <h3 id={dangerId} className="text-sm font-semibold text-destructive">
            {copy.settingsPage.danger}
          </h3>
          {danger}
        </section>
      ) : null}
    </section>
  )
}

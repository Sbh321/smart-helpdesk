import { useQueryClient } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { SettingsPage } from '@/components/shared/settings-page'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'

/** Pickers inside the settings forms ask for one large page and narrow it with `search`. */
export const PICKER_PAGE = { page: 1, per_page: 100, sort: 'name' }

export const FORM_CLASS = 'flex max-w-lg flex-col gap-4 rounded-lg border border-border p-4'

/** A directory settings page (skills, teams, categories, agents, shifts) in the shared frame (M4-12). */
export function Section({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <SettingsPage title={title} description={description}>
      {children}
    </SettingsPage>
  )
}

/**
 * The loading state of a directory list (M4-13): the shape of the rows it will show, not a bare
 * "Loading…" line; the words stay for screen readers.
 */
export function RowsSkeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-2" aria-busy="true">
      <p role="status" className="sr-only">
        {copy.settings.loading}
      </p>
      {Array.from({ length: rows }, (_, index) => (
        // biome-ignore lint/suspicious/noArrayIndexKey: placeholder rows have no identity
        <Skeleton key={index} className="h-12 w-full" />
      ))}
    </div>
  )
}

export function Saved({ show }: { show: boolean }) {
  return show ? (
    <p role="status" className="text-sm text-foreground">
      {copy.settings.saved}
    </p>
  ) : null
}

export function Rows<T extends { id: string }>({
  rows,
  render,
}: {
  rows: T[]
  render: (row: T) => ReactNode
}) {
  if (rows.length === 0) return <p className="text-sm text-muted-foreground">{copy.settings.empty}</p>
  return (
    <ul className="divide-y divide-border rounded-lg border border-border">
      {rows.map((row) => (
        <li key={row.id} className="p-3">
          {render(row)}
        </li>
      ))}
    </ul>
  )
}

export function useDirectory() {
  const tenantId = useSession().session?.tenant.id ?? ''
  return { tenantId, client: useQueryClient() }
}

/** Submit and cancel of an inline settings form. */
export function FormActions({
  isSubmitting,
  saveLabel,
  onCancel,
}: {
  isSubmitting: boolean
  saveLabel: string
  onCancel: () => void
}) {
  return (
    <div className="flex gap-2">
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? copy.settings.saving : saveLabel}
      </Button>
      <Button type="button" variant="outline" disabled={isSubmitting} onClick={onCancel}>
        {copy.settings.cancel}
      </Button>
    </div>
  )
}

/**
 * `undefined`: no form; `null`: the create form; a record: its edit form. The form is keyed by the
 * record so opening another one starts from fresh default values.
 */
export type Editing<T> = T | null | undefined

import { useNavigate, useSearch } from '@tanstack/react-router'
import { useCallback } from 'react'
import { z } from 'zod'

/**
 * The search parameters every record page keeps (roadmap M4-10): the open section (`tab`) and the
 * instant of a historical view (`as_of`, ISO UTC). In the URL, so a historical view is shareable,
 * survives a reload, and the Back button leaves it; unknown keys pass through for the page's own state.
 */
export const recordSearchSchema = z.looseObject({
  tab: z.string().min(1).max(40).optional().catch(undefined),
  as_of: z.iso.datetime({ offset: true }).optional().catch(undefined),
})

export type RecordSearch = z.infer<typeof recordSearchSchema>

export interface RecordView {
  /** The open section, or `fallback` when the URL names none. */
  tab: string
  /** The instant of the historical view, or null for the live record. */
  asOf: string | null
  setTab: (tab: string) => void
  /** Opens the historical view at an instant (on the History section), or returns to now with null. */
  setAsOf: (at: string | null) => void
}

/** Reads and writes the record page's `tab` and `as_of` in the URL of whichever record route is open. */
export function useRecordView(fallback: string): RecordView {
  const search = useSearch({ strict: false }) as RecordSearch
  const navigate = useNavigate()
  // Stable setters: effects (keyboard shortcuts) may depend on them without re-subscribing each render.
  const write = useCallback(
    (patch: Partial<RecordSearch>) =>
      void navigate({
        to: '.',
        search: ((previous: Record<string, unknown>) => {
          const next = { ...previous, ...patch }
          for (const key of Object.keys(patch) as (keyof RecordSearch)[]) {
            if (next[key] === undefined) delete next[key]
          }
          return next
        }) as never,
      }),
    [navigate],
  )
  // The default section is left out of the URL, as every default is.
  const setTab = useCallback(
    (tab: string) => write({ tab: tab === fallback ? undefined : tab }),
    [write, fallback],
  )
  const setAsOf = useCallback(
    (at: string | null) => write(at === null ? { as_of: undefined } : { as_of: at, tab: 'history' }),
    [write],
  )
  return { tab: search.tab ?? fallback, asOf: search.as_of ?? null, setTab, setAsOf }
}

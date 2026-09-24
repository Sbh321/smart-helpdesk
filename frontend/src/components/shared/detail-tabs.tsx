import type { ReactNode } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useRecordView } from '@/lib/record-view'

/** One extra section of a record page, for example Overview or History (roadmap M3-21). */
export interface DetailTab {
  value: string
  label: string
  content: ReactNode
}

export interface DetailTabsProps {
  /** Accessible name of the tab list. */
  label: string
  /** Label of the first tab, which holds `details`. */
  detailsLabel?: string
  /** The record itself; without it the first of `tabs` opens first (read-only records). */
  details?: ReactNode
  /** Further sections; without any, `details` is rendered on its own, with no tab list. */
  tabs: readonly DetailTab[]
}

/**
 * A record page's sections as tabs: the record itself first, then the sections a route adds (features
 * may not import one another's screens, so the route passes them in). Only the open panel is mounted,
 * so a section's queries run when it is opened and refresh when it is opened again. The open section
 * is `?tab=` in the URL (M4-10), so leaving a historical view returns to the section it was opened
 * from, and a link can open History directly.
 */
export function DetailTabs({ label, detailsLabel, details, tabs }: DetailTabsProps) {
  const all: DetailTab[] = [
    ...(details !== undefined ? [{ value: 'details', label: detailsLabel ?? '', content: details }] : []),
    ...tabs,
  ]
  const fallback = all[0]?.value ?? 'details'
  const { tab, setTab } = useRecordView(fallback)
  const value = all.some((item) => item.value === tab) ? tab : fallback
  if (tabs.length === 0) return <>{details}</>
  return (
    <Tabs value={value} onValueChange={(next) => setTab(String(next))}>
      <TabsList aria-label={label}>
        {all.map((item) => (
          <TabsTrigger key={item.value} value={item.value}>
            {item.label}
          </TabsTrigger>
        ))}
      </TabsList>
      {all.map((item) => (
        <TabsContent key={item.value} value={item.value} className="pt-4">
          {item.content}
        </TabsContent>
      ))}
    </Tabs>
  )
}

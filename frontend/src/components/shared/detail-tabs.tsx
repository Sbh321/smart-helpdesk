import { type ReactNode, useState } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'

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
  detailsLabel: string
  details: ReactNode
  /** Further sections; without any, `details` is rendered on its own, with no tab list. */
  tabs: readonly DetailTab[]
}

/**
 * A record page's sections as tabs: the record itself first, then the sections a route adds (features
 * may not import one another's screens, so the route passes them in). Only the open panel is mounted,
 * so a section's queries run when it is opened and refresh when it is opened again.
 */
export function DetailTabs({ label, detailsLabel, details, tabs }: DetailTabsProps) {
  const [value, setValue] = useState('details')
  if (tabs.length === 0) return <>{details}</>
  return (
    <Tabs value={value} onValueChange={(next) => setValue(String(next))}>
      <TabsList aria-label={label}>
        <TabsTrigger value="details">{detailsLabel}</TabsTrigger>
        {tabs.map((tab) => (
          <TabsTrigger key={tab.value} value={tab.value}>
            {tab.label}
          </TabsTrigger>
        ))}
      </TabsList>
      <TabsContent value="details" className="pt-4">
        {details}
      </TabsContent>
      {tabs.map((tab) => (
        <TabsContent key={tab.value} value={tab.value} className="pt-4">
          {tab.content}
        </TabsContent>
      ))}
    </Tabs>
  )
}

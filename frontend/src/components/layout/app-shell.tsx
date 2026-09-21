import type { ReactNode } from 'react'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
import { Sidebar } from './sidebar'
import { Topbar } from './topbar'

/**
 * The authenticated frame: skip link, `nav` landmark, `header` landmark and one `main` with the route's
 * content (docs/06-design-system/accessibility.md §Added by us). The page's `h1` lives in the route.
 */
export function AppShell({
  workspace,
  topbarActions,
  topbarNotifications,
  children,
}: {
  workspace: string
  topbarActions?: ReactNode
  topbarNotifications?: ReactNode
  children: ReactNode
}) {
  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <SkipLink />
      <div className="flex min-h-dvh">
        <Sidebar workspace={workspace} />
        <div className="flex min-w-0 flex-1 flex-col">
          <Topbar workspace={workspace} actions={topbarActions} notifications={topbarNotifications} />
          <main id={MAIN_CONTENT_ID} tabIndex={-1} className="flex flex-1 flex-col gap-6 p-6 outline-none">
            {children}
          </main>
        </div>
      </div>
    </div>
  )
}

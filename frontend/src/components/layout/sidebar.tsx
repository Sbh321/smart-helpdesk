import { Link } from '@tanstack/react-router'
import { PanelLeftCloseIcon, PanelLeftOpenIcon } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { useMediaQuery, WIDE_QUERY } from '@/lib/use-media-query'
import { cn } from '@/lib/utils'
import { NAV_GROUPS, visibleNavItems } from './nav-items'
import { TenantLogo } from './tenant-logo'

const COLLAPSED_STORAGE_KEY = 'sh.nav-collapsed'

/** The viewer's last choice, or expanded when the browser refuses storage (private windows). */
function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(COLLAPSED_STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}

/**
 * Primary navigation (docs/06-design-system/accessibility.md §Added by us): one `nav` landmark holding
 * lists of links, grouped into Work, Records, Insight and Administration (roadmap M4-02) so the six
 * destinations read as a structure rather than a flat stack. Items the session has no permission for
 * are not rendered at all.
 *
 * Collapsing leaves an icon rail: the labels become tooltips and accessible names, and the choice is
 * remembered per browser. A group whose items are all hidden by permission renders no heading.
 *
 * Below 1280 px (M4-13, docs/06-design-system/responsive.md) the rail is the default, so the work area
 * keeps its width at 1024 px; it can still be opened for the visit, and the remembered choice applies
 * only to wide windows.
 */
export function Sidebar({ workspace }: { workspace: string }) {
  const { session, permissions } = useSession()
  const items = visibleNavItems(permissions)
  const wide = useMediaQuery(WIDE_QUERY)
  const [stored, setStored] = useState(readCollapsed)
  const [narrowOpen, setNarrowOpen] = useState(false)
  const collapsed = wide ? stored : !narrowOpen

  useEffect(() => {
    try {
      window.localStorage.setItem(COLLAPSED_STORAGE_KEY, String(stored))
    } catch {
      // A browser that refuses storage still navigates; only the preference is lost.
    }
  }, [stored])

  const toggle = useCallback(() => {
    if (wide) setStored((previous) => !previous)
    else setNarrowOpen((previous) => !previous)
  }, [wide])

  return (
    <nav
      aria-label={copy.nav.primary}
      data-collapsed={collapsed || undefined}
      className={cn(
        'flex shrink-0 flex-col gap-3 border-border border-r bg-surface p-3 print:hidden',
        collapsed ? 'w-14 items-center' : 'w-56',
      )}
    >
      <div className={cn('flex items-center gap-2 px-1 py-1', collapsed && 'px-0')}>
        <TenantLogo branding={session?.tenant.branding} />
        {collapsed ? null : (
          <span className="truncate font-semibold text-sm">{session?.tenant.name ?? copy.app.name}</span>
        )}
      </div>

      {NAV_GROUPS.map(({ group, label }) => {
        const groupItems = items.filter((item) => item.group === group)
        if (groupItems.length === 0) return null

        return (
          <div key={group} className="flex w-full flex-col gap-0.5">
            {label && !collapsed ? (
              <h2 className="px-2 pt-2 pb-1 font-medium text-muted-foreground text-xs uppercase tracking-wide">
                {label}
              </h2>
            ) : null}
            <ul className="flex flex-col gap-0.5">
              {groupItems.map((item) => {
                const Icon = item.icon
                const link = (
                  <Link
                    to={item.to}
                    params={{ workspace }}
                    activeOptions={{ exact: item.to === '/$workspace' }}
                    aria-label={collapsed ? item.label : undefined}
                    className={cn(
                      'flex items-center gap-2 rounded-lg px-2 py-1.5 text-muted-foreground text-sm transition-colors',
                      'hover:bg-muted hover:text-foreground',
                      'focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-2',
                      'data-[status=active]:bg-muted data-[status=active]:font-medium data-[status=active]:text-foreground',
                      collapsed && 'justify-center px-0',
                    )}
                  >
                    <Icon aria-hidden="true" className="size-4 shrink-0" />
                    {collapsed ? null : item.label}
                  </Link>
                )

                return (
                  <li key={item.key}>
                    {collapsed ? (
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger render={link} />
                          <TooltipContent side="right">{item.label}</TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    ) : (
                      link
                    )}
                  </li>
                )
              })}
            </ul>
          </div>
        )
      })}

      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        onClick={toggle}
        aria-label={collapsed ? copy.nav.expand : copy.nav.collapse}
        aria-pressed={collapsed}
        className="mt-auto"
      >
        {collapsed ? <PanelLeftOpenIcon aria-hidden="true" /> : <PanelLeftCloseIcon aria-hidden="true" />}
      </Button>
    </nav>
  )
}

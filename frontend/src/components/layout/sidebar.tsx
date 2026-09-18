import { Link } from '@tanstack/react-router'
import { LifeBuoyIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { cn } from '@/lib/utils'
import { visibleNavItems } from './nav-items'

/**
 * Primary navigation (docs/06-design-system/accessibility.md §Added by us): a single `nav` landmark
 * holding a list of links. Items the session has no permission for are not rendered at all.
 */
export function Sidebar({ workspace }: { workspace: string }) {
  const { session, permissions } = useSession()
  const items = visibleNavItems(permissions)

  return (
    <nav
      aria-label={copy.nav.primary}
      className="flex w-56 shrink-0 flex-col gap-4 border-r border-border bg-surface p-3"
    >
      <div className="flex items-center gap-2 px-1 py-1">
        <LifeBuoyIcon aria-hidden="true" className="size-5 text-primary" />
        <span className="truncate text-sm font-semibold">{session?.tenant.name ?? copy.app.name}</span>
      </div>

      <ul className="flex flex-col gap-0.5">
        {items.map((item) => {
          const Icon = item.icon
          return (
            <li key={item.key}>
              <Link
                to={item.to}
                params={{ workspace }}
                activeOptions={{ exact: item.to === '/$workspace' }}
                className={cn(
                  'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground transition-colors',
                  'hover:bg-muted hover:text-foreground',
                  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                  'data-[status=active]:bg-muted data-[status=active]:font-medium data-[status=active]:text-foreground',
                )}
              >
                <Icon aria-hidden="true" className="size-4" />
                {item.label}
              </Link>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

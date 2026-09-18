import { LogOutIcon } from 'lucide-react'
import { ThemeToggle } from '@/components/shared/theme-toggle'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { Breadcrumbs } from './breadcrumbs'
import { CommandPalette } from './command-palette'
import { NotificationBell } from './notification-bell'

function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || '?'
  )
}

/** The `header` landmark: breadcrumbs on the left, palette, bell, theme and account menu on the right. */
export function Topbar({ workspace }: { workspace: string }) {
  const { session, signOut } = useSession()

  return (
    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2">
      <Breadcrumbs workspace={workspace} />
      <div className="flex items-center gap-2">
        <CommandPalette workspace={workspace} />
        <NotificationBell />
        <ThemeToggle />
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button variant="ghost" size="icon-sm" aria-label={copy.nav.account} className="rounded-full" />
            }
          >
            <Avatar className="size-7">
              <AvatarFallback>{initials(session?.user.name ?? '')}</AvatarFallback>
            </Avatar>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            {/* Base UI requires a group around a group label, so the account details label the menu. */}
            <DropdownMenuGroup>
              <DropdownMenuLabel>
                <span className="block truncate font-medium">{session?.user.name}</span>
                <span className="block truncate text-xs font-normal text-muted-foreground">
                  {session?.user.email}
                </span>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => void signOut()}>
                <LogOutIcon aria-hidden="true" />
                {copy.auth.signOut}
              </DropdownMenuItem>
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  )
}

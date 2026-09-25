import { LogOutIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { useState } from 'react'
import { AppearanceMenu } from '@/components/shared/appearance-menu'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
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
import { Hint } from '@/components/ui/tooltip'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { initials } from '@/lib/format/initials'
import { Breadcrumbs } from './breadcrumbs'
import { CommandPalette } from './command-palette'
import { ConnectionIndicator } from './connection-indicator'

/**
 * The `header` landmark (roadmap M4-02): breadcrumbs, then search in the prime central slot, then the
 * status and account controls. Theme and density moved into the account menu (`AppearanceMenu`), which
 * gives search the width it deserves as the fastest route to any record
 * (docs/06-design-system/ux-review.md G7).
 *
 * `actions` and `notifications` are slots the route fills with feature-owned controls (the agent
 * availability control, the notification bell): the shell may not import features.
 */
export function Topbar({
  workspace,
  actions,
  notifications,
}: {
  workspace: string
  actions?: ReactNode
  notifications?: ReactNode
}) {
  const { session, signOut } = useSession()
  const [confirmingSignOut, setConfirmingSignOut] = useState(false)

  return (
    <header className="flex items-center gap-3 border-border border-b bg-surface px-4 py-2 print:hidden">
      <div className="hidden min-w-0 shrink lg:block">
        <Breadcrumbs workspace={workspace} />
      </div>
      <div className="min-w-0 flex-1 md:max-w-md lg:mx-auto">
        <CommandPalette workspace={workspace} />
      </div>
      <div className="flex shrink-0 items-center gap-1.5">
        {actions}
        <ConnectionIndicator />
        {notifications}
        <DropdownMenu>
          <Hint label={copy.nav.account}>
            <DropdownMenuTrigger
              render={
                <Button
                  variant="ghost"
                  size="icon-sm"
                  aria-label={copy.nav.account}
                  className="rounded-full"
                />
              }
            >
              <Avatar className="size-7">
                <AvatarFallback>{initials(session?.user.name ?? '')}</AvatarFallback>
              </Avatar>
            </DropdownMenuTrigger>
          </Hint>
          <DropdownMenuContent align="end" className="w-60">
            {/* Base UI requires a group around a group label, so the account details label the menu. */}
            <DropdownMenuGroup>
              <DropdownMenuLabel>
                <span className="block truncate font-medium">{session?.user.name}</span>
                <span className="block truncate font-normal text-muted-foreground text-xs">
                  {session?.user.email}
                </span>
              </DropdownMenuLabel>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <AppearanceMenu />
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              <DropdownMenuItem onClick={() => setConfirmingSignOut(true)}>
                <LogOutIcon aria-hidden="true" />
                {copy.auth.signOut}
              </DropdownMenuItem>
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
        <ConfirmDialog
          open={confirmingSignOut}
          onOpenChange={setConfirmingSignOut}
          title={copy.auth.signOutConfirm.title}
          description={copy.auth.signOutConfirm.description}
          confirmLabel={copy.auth.signOut}
          failedTitle={copy.auth.signOutConfirm.failed}
          onConfirm={signOut}
        />
      </div>
    </header>
  )
}

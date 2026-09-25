import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createFileRoute, Outlet, redirect, useNavigate } from '@tanstack/react-router'
import { BookOpenIcon, LogOutIcon, ShieldIcon } from 'lucide-react'
import { AppearanceMenu } from '@/components/shared/appearance-menu'
import { ExternalLinkButton } from '@/components/shared/external-link-button'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
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
import { copy, fill } from '@/copy/en'
import { ensurePlatformSession, platformLogout, platformSessionQuery } from '@/features/platform'
import { queryKeys } from '@/lib/api/query-keys'
import { initials } from '@/lib/format/initials'

/**
 * Platform administration ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)): served from
 * `admin.<domain>` with its own guard and its own cookie; `/platform-api/*` is same-origin there.
 *
 * MVP-SHORTCUT: a sign-in page and a read-only tenant list only; V1: the full console (V1-PL-13).
 */
export const Route = createFileRoute('/_platform')({
  beforeLoad: async ({ context, location }) => {
    if (!(await ensurePlatformSession(context.queryClient))) {
      throw redirect({ to: '/platform/login', search: { redirect: location.href }, replace: true })
    }
  },
  component: PlatformLayout,
})

function PlatformLayout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data: admin } = useQuery(platformSessionQuery())

  const signOut = async () => {
    try {
      await platformLogout()
    } finally {
      queryClient.removeQueries({ queryKey: queryKeys.platform.all })
      await navigate({ to: '/platform/login', replace: true })
    }
  }

  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <SkipLink />
      <header className="flex items-center justify-between gap-4 border-b border-border bg-surface px-4 py-2">
        <span className="flex items-center gap-2 text-sm font-semibold">
          <ShieldIcon aria-hidden="true" className="size-5 text-primary" />
          {copy.platform.heading}
        </span>
        <ExternalLinkButton href="/platform/docs" variant="ghost" size="sm" className="ms-auto">
          <BookOpenIcon aria-hidden="true" />
          {copy.platform.platformDocs}
        </ExternalLinkButton>
        {/* The account menu of the workspace shell (M4-02): who is signed in, theme and density, sign-out.
            The bar itself carries no appearance controls (docs/06-design-system/ux-review.md G7). */}
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button variant="ghost" size="icon-sm" aria-label={copy.nav.account} className="rounded-full" />
            }
          >
            <Avatar className="size-7">
              <AvatarFallback>{initials(admin?.name ?? '')}</AvatarFallback>
            </Avatar>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-60">
            <DropdownMenuGroup>
              <DropdownMenuLabel>
                <span className="block truncate font-medium">
                  {admin ? fill(copy.platform.signedInAs, { name: admin.name }) : copy.platform.heading}
                </span>
                {admin ? (
                  <span className="block truncate font-normal text-muted-foreground text-xs">
                    {admin.email}
                  </span>
                ) : null}
              </DropdownMenuLabel>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <AppearanceMenu />
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              <DropdownMenuItem onClick={() => void signOut()}>
                <LogOutIcon aria-hidden="true" />
                {copy.platform.signOut}
              </DropdownMenuItem>
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      </header>
      <main id={MAIN_CONTENT_ID} tabIndex={-1} className="flex flex-1 flex-col gap-6 p-6 outline-none">
        <Outlet />
      </main>
    </div>
  )
}

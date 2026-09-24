import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createFileRoute, Outlet, redirect, useNavigate } from '@tanstack/react-router'
import { LogOutIcon, ShieldIcon } from 'lucide-react'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
import { ThemeToggle } from '@/components/shared/theme-toggle'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { ensurePlatformSession, platformLogout, platformSessionQuery } from '@/features/platform'
import { queryKeys } from '@/lib/api/query-keys'

/**
 * Platform administration ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)): served from
 * `admin.<domain>` with its own guard and its own cookie; `/platform-api/*` is same-origin there.
 *
 * MVP-SHORTCUT: a sign-in page and a read-only tenant list only; V1: the full console (V1-PL-13).
 */
export const Route = createFileRoute('/_platform')({
  beforeLoad: async ({ context }) => {
    if (!(await ensurePlatformSession(context.queryClient))) {
      throw redirect({ to: '/platform/login', replace: true })
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
        <div className="flex items-center gap-2">
          {admin ? (
            <span className="hidden text-sm text-muted-foreground sm:inline">
              {fill(copy.platform.signedInAs, { name: admin.name })}
            </span>
          ) : null}
          <ThemeToggle />
          <Button variant="outline" size="sm" onClick={() => void signOut()}>
            <LogOutIcon aria-hidden="true" />
            {copy.platform.signOut}
          </Button>
        </div>
      </header>
      <main id={MAIN_CONTENT_ID} tabIndex={-1} className="flex flex-1 flex-col gap-6 p-6 outline-none">
        <Outlet />
      </main>
    </div>
  )
}

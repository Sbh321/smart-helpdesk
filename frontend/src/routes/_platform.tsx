import { createFileRoute, Outlet } from '@tanstack/react-router'
import { ShieldIcon } from 'lucide-react'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
import { ThemeToggle } from '@/components/shared/theme-toggle'
import { copy } from '@/copy/en'

/**
 * Platform administration ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)): served from
 * `admin.<domain>` with its own guard and its own cookie. Neither exists yet, so the shell is a plain
 * frame around whatever the pages can show.
 *
 * MVP-SHORTCUT: no platform session and no host check in the SPA yet, although the platform API and
 * its `platform` guard exist since M1-07; V1: serve this console on the admin host and call
 * `/platform-api/*` with the platform session (V1-PL-13).
 */
export const Route = createFileRoute('/_platform')({
  component: PlatformLayout,
})

function PlatformLayout() {
  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <SkipLink />
      <header className="flex items-center justify-between border-b border-border bg-surface px-4 py-2">
        <span className="flex items-center gap-2 text-sm font-semibold">
          <ShieldIcon aria-hidden="true" className="size-5 text-primary" />
          {copy.platform.heading}
        </span>
        <ThemeToggle />
      </header>
      <main id={MAIN_CONTENT_ID} tabIndex={-1} className="flex flex-1 flex-col gap-6 p-6 outline-none">
        <Outlet />
      </main>
    </div>
  )
}

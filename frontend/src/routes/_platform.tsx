import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createFileRoute, Link, Outlet, redirect, useNavigate } from '@tanstack/react-router'
import {
  ActivityIcon,
  BookOpenIcon,
  Building2Icon,
  LayersIcon,
  LayoutDashboardIcon,
  LogOutIcon,
  type LucideIcon,
  ReceiptIcon,
  SettingsIcon,
  ShieldCheckIcon,
  UserCogIcon,
} from 'lucide-react'
import { useState } from 'react'
import { AppearanceMenu } from '@/components/shared/appearance-menu'
import { BrandMark } from '@/components/shared/brand-mark'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
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
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import {
  ensurePlatformSession,
  platformLogout,
  platformPaymentsQuery,
  platformSessionQuery,
} from '@/features/platform'
import { queryKeys } from '@/lib/api/query-keys'
import { initials } from '@/lib/format/initials'

/**
 * Platform administration ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md)): served from
 * `admin.<domain>` with its own guard and its own cookie; `/platform-api/*` is same-origin there.
 *
 * The console (ADR-0025): dashboard, workspaces, payments, plans, admins and settings, one tab each.
 */
export const Route = createFileRoute('/_platform')({
  beforeLoad: async ({ context, location }) => {
    if (!(await ensurePlatformSession(context.queryClient))) {
      throw redirect({ to: '/platform/login', search: { redirect: location.href }, replace: true })
    }
  },
  component: PlatformLayout,
})

const NAV: { to: string; label: string; icon: LucideIcon; exact?: boolean; key: string }[] = [
  {
    key: 'dashboard',
    to: '/platform',
    label: copy.platform.nav.dashboard,
    icon: LayoutDashboardIcon,
    exact: true,
  },
  { key: 'workspaces', to: '/platform/tenants', label: copy.platform.nav.workspaces, icon: Building2Icon },
  { key: 'payments', to: '/platform/payments', label: copy.platform.nav.payments, icon: ReceiptIcon },
  { key: 'plans', to: '/platform/plans', label: copy.platform.nav.plans, icon: LayersIcon },
  { key: 'admins', to: '/platform/admins', label: copy.platform.nav.admins, icon: ShieldCheckIcon },
  { key: 'settings', to: '/platform/settings', label: copy.platform.nav.settings, icon: SettingsIcon },
]

function PlatformLayout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data: admin } = useQuery(platformSessionQuery())
  // How many receipts wait, as a count on the Payments tab.
  const pending = useQuery({ ...platformPaymentsQuery({ status: 'pending' }), refetchInterval: 60_000 }).data
    ?.meta.total
  const [confirmingSignOut, setConfirmingSignOut] = useState(false)

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
          <BrandMark showName={false} size="sm" />
          {copy.platform.heading}
        </span>
        <div className="ms-auto flex items-center gap-1">
          <ExternalLinkButton href="/platform/monitor" variant="ghost" size="sm">
            <ActivityIcon aria-hidden="true" />
            {copy.platform.monitoring}
          </ExternalLinkButton>
          <ExternalLinkButton href="/platform/docs" variant="ghost" size="sm">
            <BookOpenIcon aria-hidden="true" />
            {copy.platform.platformDocs}
          </ExternalLinkButton>
        </div>
        {/* The account menu of the workspace shell (M4-02): who is signed in, theme and density, sign-out.
            The bar itself carries no appearance controls (docs/06-design-system/ux-review.md G7). */}
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
                <AvatarFallback>{initials(admin?.name ?? '')}</AvatarFallback>
              </Avatar>
            </DropdownMenuTrigger>
          </Hint>
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
            <DropdownMenuGroup>
              <DropdownMenuItem onClick={() => void navigate({ to: '/platform/account' })}>
                <UserCogIcon aria-hidden="true" />
                {copy.platform.accountMenu}
              </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <AppearanceMenu />
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              <DropdownMenuItem onClick={() => setConfirmingSignOut(true)}>
                <LogOutIcon aria-hidden="true" />
                {copy.platform.signOut}
              </DropdownMenuItem>
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
        <ConfirmDialog
          open={confirmingSignOut}
          onOpenChange={setConfirmingSignOut}
          title={copy.auth.signOutConfirm.title}
          description={copy.auth.signOutConfirm.platformDescription}
          confirmLabel={copy.platform.signOut}
          failedTitle={copy.auth.signOutConfirm.failed}
          onConfirm={signOut}
        />
      </header>
      <nav aria-label={copy.platform.nav.label} className="border-border border-b bg-surface px-2 sm:px-4">
        <ul className="-mb-px flex gap-1 overflow-x-auto">
          {NAV.map((item) => {
            const Icon = item.icon
            const count = item.key === 'payments' ? (pending ?? 0) : 0
            return (
              <li key={item.key} className="shrink-0">
                <Link
                  to={item.to}
                  activeOptions={{ exact: item.exact ?? false }}
                  className="flex items-center gap-2 border-transparent border-b-2 px-3 py-2.5 font-medium text-muted-foreground text-sm outline-none transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 data-[status=active]:border-primary data-[status=active]:text-foreground"
                >
                  <Icon aria-hidden="true" className="size-4" />
                  {item.label}
                  {count > 0 ? (
                    <>
                      <span
                        aria-hidden="true"
                        className="rounded-full bg-primary px-1.5 font-semibold text-[11px] text-primary-foreground leading-5 tabular-nums"
                      >
                        {count}
                      </span>
                      <span className="sr-only">, {fill(copy.platform.nav.pendingCount, { count })}</span>
                    </>
                  ) : null}
                </Link>
              </li>
            )
          })}
        </ul>
      </nav>
      <main
        id={MAIN_CONTENT_ID}
        tabIndex={-1}
        className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 outline-none sm:p-6"
      >
        <Outlet />
      </main>
    </div>
  )
}

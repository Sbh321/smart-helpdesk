import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { ensurePlatformSession, PlatformLoginForm, platformRedirect } from '@/features/platform'

/** Platform Super Admin sign-in on the admin host; a signed-in admin goes straight to the tenants. */
const searchSchema = z.object({
  /** The console page to return to after sign-in (the platform docs hand-off, M5-06). */
  redirect: z.string().optional(),
})

export const Route = createFileRoute('/platform/login')({
  validateSearch: searchSchema,
  beforeLoad: async ({ context, search }) => {
    if (await ensurePlatformSession(context.queryClient)) {
      throw redirect({ href: platformRedirect(search.redirect) ?? '/platform', replace: true })
    }
  },
  component: PlatformLoginPage,
})

function PlatformLoginPage() {
  const { redirect: returnTo } = Route.useSearch()
  return (
    <AuthLayout title={copy.platform.login.heading} description={copy.platform.login.body} variant="platform">
      <PlatformLoginForm redirect={returnTo} />
    </AuthLayout>
  )
}

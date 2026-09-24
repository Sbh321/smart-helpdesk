import { createFileRoute, redirect } from '@tanstack/react-router'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { ensurePlatformSession, PlatformLoginForm } from '@/features/platform'

/** Platform Super Admin sign-in on the admin host; a signed-in admin goes straight to the tenants. */
export const Route = createFileRoute('/platform/login')({
  beforeLoad: async ({ context }) => {
    if (await ensurePlatformSession(context.queryClient)) {
      throw redirect({ to: '/platform/tenants', replace: true })
    }
  },
  component: PlatformLoginPage,
})

function PlatformLoginPage() {
  return (
    <AuthLayout title={copy.platform.login.heading} description={copy.platform.login.body}>
      <PlatformLoginForm />
    </AuthLayout>
  )
}

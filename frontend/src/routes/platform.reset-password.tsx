import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy, fill } from '@/copy/en'
import { PlatformResetPasswordForm } from '@/features/platform'

/** The link from the reset email: a new password (ADR-0025 §7). */
export const Route = createFileRoute('/platform/reset-password')({
  validateSearch: z.object({
    token: z.string().catch('').default(''),
    email: z.string().catch('').default(''),
  }),
  component: function ResetPage() {
    const { token, email } = Route.useSearch()
    return (
      <AuthLayout
        title={copy.platform.recovery.resetTitle}
        description={email ? fill(copy.platform.recovery.resetBody, { email }) : undefined}
        variant="platform"
      >
        <PlatformResetPasswordForm token={token} email={email} />
      </AuthLayout>
    )
  },
})

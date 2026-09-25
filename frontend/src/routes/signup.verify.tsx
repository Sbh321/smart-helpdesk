import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { VerifySignup } from '@/features/signup'

/** The link from the sign-up email (ADR-0025 §8). */
export const Route = createFileRoute('/signup/verify')({
  validateSearch: z.object({ token: z.string().catch('').default('') }),
  component: function VerifyPage() {
    const { token } = Route.useSearch()
    return (
      <AuthLayout title={copy.signup.verifyTitle}>
        <VerifySignup token={token} />
      </AuthLayout>
    )
  },
})

import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { PlatformAcceptInvitation } from '@/features/platform'

/** The link from an admin invitation (ADR-0025 §7). */
export const Route = createFileRoute('/platform/accept-invitation')({
  validateSearch: z.object({ token: z.string().catch('').default('') }),
  component: function AcceptPage() {
    const { token } = Route.useSearch()
    return (
      <AuthLayout title={copy.platform.invitation.title} variant="platform">
        <PlatformAcceptInvitation token={token} />
      </AuthLayout>
    )
  },
})

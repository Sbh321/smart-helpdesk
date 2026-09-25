import { createFileRoute } from '@tanstack/react-router'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { PlatformForgotPasswordForm } from '@/features/platform'

/** A platform admin asks for a reset link (ADR-0025 §7). */
export const Route = createFileRoute('/platform/forgot-password')({
  component: () => (
    <AuthLayout
      title={copy.platform.recovery.forgotTitle}
      description={copy.platform.recovery.forgotBody}
      variant="platform"
    >
      <PlatformForgotPasswordForm />
    </AuthLayout>
  ),
})

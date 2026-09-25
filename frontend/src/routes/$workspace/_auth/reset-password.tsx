import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy, fill } from '@/copy/en'
import { RequestPasswordResetForm, SetNewPasswordForm, WorkspaceChip } from '@/features/auth'

const searchSchema = z.object({
  /** Present when the visitor followed the emailed link; absent when they asked for one. */
  token: z.string().optional(),
  email: z.string().optional(),
})

export const Route = createFileRoute('/$workspace/_auth/reset-password')({
  validateSearch: searchSchema,
  component: ResetPasswordPage,
})

function ResetPasswordPage() {
  const { workspace } = Route.useParams()
  const { token, email } = Route.useSearch()
  const heading = token ? copy.auth.resetPassword : copy.auth.forgotPassword

  return (
    <AuthLayout
      title={heading.heading}
      description={fill(heading.body, { workspace })}
      eyebrow={<WorkspaceChip workspace={workspace} />}
    >
      {token ? (
        <SetNewPasswordForm workspace={workspace} token={token} email={email} />
      ) : (
        <RequestPasswordResetForm workspace={workspace} />
      )}
    </AuthLayout>
  )
}

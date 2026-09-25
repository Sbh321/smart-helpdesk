import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { LoginForm, WorkspaceChip } from '@/features/auth'

const searchSchema = z.object({
  /** Where to go after signing in; sanitised to a same-origin path before it is used. */
  redirect: z.string().optional(),
})

export const Route = createFileRoute('/$workspace/_auth/login')({
  validateSearch: searchSchema,
  component: LoginPage,
})

function LoginPage() {
  const { workspace } = Route.useParams()
  const { redirect } = Route.useSearch()

  return (
    <AuthLayout
      title={copy.auth.login.heading}
      description={copy.auth.login.body}
      eyebrow={<WorkspaceChip workspace={workspace} />}
    >
      <LoginForm workspace={workspace} redirect={redirect} />
    </AuthLayout>
  )
}

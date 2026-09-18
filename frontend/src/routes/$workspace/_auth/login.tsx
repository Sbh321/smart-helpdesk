import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy, fill } from '@/copy/en'
import { LoginForm } from '@/features/auth'

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
    <AuthLayout title={copy.auth.login.heading} description={fill(copy.auth.login.body, { workspace })}>
      <LoginForm workspace={workspace} redirect={redirect} />
    </AuthLayout>
  )
}

import { createFileRoute, Link } from '@tanstack/react-router'
import { z } from 'zod'
import { AuthLayout } from '@/components/layout/auth-layout'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { copy, fill } from '@/copy/en'
import { AcceptInvitationForm } from '@/features/auth'

const searchSchema = z.object({
  /** The 64-character invitation token from the emailed link (docs/07-api/authentication.md §2). */
  token: z.string().optional(),
})

export const Route = createFileRoute('/$workspace/_auth/accept-invitation')({
  validateSearch: searchSchema,
  component: AcceptInvitationPage,
})

function AcceptInvitationPage() {
  const { workspace } = Route.useParams()
  const { token } = Route.useSearch()

  return (
    <AuthLayout
      title={copy.auth.acceptInvitation.heading}
      description={fill(copy.auth.acceptInvitation.body, { workspace })}
    >
      {token ? (
        <AcceptInvitationForm workspace={workspace} token={token} />
      ) : (
        <div className="flex flex-col gap-4">
          <Alert variant="destructive">
            <AlertTitle>{copy.auth.acceptInvitation.failed}</AlertTitle>
            <AlertDescription>{copy.auth.acceptInvitation.missingToken}</AlertDescription>
          </Alert>
          <Link
            to="/$workspace/login"
            params={{ workspace }}
            search={{}}
            className="text-sm underline underline-offset-4 hover:text-primary"
          >
            {copy.auth.forgotPassword.backToLogin}
          </Link>
        </div>
      )}
    </AuthLayout>
  )
}

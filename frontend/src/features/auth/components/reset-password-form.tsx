import { useForm } from '@tanstack/react-form'
import { Link, useNavigate } from '@tanstack/react-router'
import { CheckCircle2Icon } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { TextField } from '@/components/shared/text-field'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { requestPasswordReset, resetPassword } from '../api/auth-requests'
import { authErrorMessage, fieldErrorsOf, isFieldError } from '../auth-errors'
import { forgotPasswordSchema, resetPasswordSchema } from '../schemas'
import { AuthErrorBanner } from './auth-error-banner'

function BackToLogin({ workspace }: { workspace: string }) {
  return (
    <Link
      to="/$workspace/login"
      params={{ workspace }}
      search={{}}
      className="text-sm underline underline-offset-4 hover:text-primary"
    >
      {copy.auth.forgotPassword.backToLogin}
    </Link>
  )
}

/**
 * Asks the API for a reset link. The API always answers 202, so the confirmation never reveals whether
 * the address belongs to an account (docs/07-api/authentication.md §2).
 */
export function RequestPasswordResetForm({ workspace }: { workspace: string }) {
  const [banner, setBanner] = useState<string | null>(null)
  const [sent, setSent] = useState(false)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: '' },
    validators: { onSubmit: forgotPasswordSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await requestPasswordReset({ workspace, email: value.email })
      } catch (error) {
        if (isFieldError(error)) {
          setServerErrors(fieldErrorsOf(error))
          return
        }
        setBanner(authErrorMessage(error))
        return
      }
      setSent(true)
    },
  })

  if (sent) {
    return (
      <div className="flex flex-col gap-4">
        <Alert>
          <CheckCircle2Icon aria-hidden="true" />
          <AlertTitle>{copy.auth.forgotPassword.sent}</AlertTitle>
          <AlertDescription>{copy.auth.forgotPassword.body}</AlertDescription>
        </Alert>
        <BackToLogin workspace={workspace} />
      </div>
    )
  }

  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
      className="flex flex-col gap-5"
    >
      {banner ? <AuthErrorBanner title={copy.auth.forgotPassword.failed} message={banner} /> : null}

      <FieldGroup>
        <TextField
          id="forgot-workspace"
          label={copy.auth.workspaceLabel}
          value={workspace}
          onValueChange={() => undefined}
          readOnly
          errors={serverErrors.workspace}
        />
        <form.Field name="email">
          {(field) => (
            <TextField
              id="forgot-email"
              label={copy.auth.emailLabel}
              type="email"
              autoComplete="username"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.email)}
            />
          )}
        </form.Field>
      </FieldGroup>

      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? copy.auth.forgotPassword.submitting : copy.auth.forgotPassword.submit}
          </Button>
        )}
      </form.Subscribe>

      <BackToLogin workspace={workspace} />
    </form>
  )
}

/** Sets a new password with the token from the emailed link. */
export function SetNewPasswordForm({
  workspace,
  token,
  email,
}: {
  workspace: string
  token: string
  email?: string
}) {
  const navigate = useNavigate()
  const [banner, setBanner] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: email ?? '', password: '', password_confirmation: '' },
    validators: { onSubmit: resetPasswordSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await resetPassword({
          workspace,
          token,
          email: value.email,
          password: value.password,
          password_confirmation: value.password_confirmation,
        })
      } catch (error) {
        if (isFieldError(error)) {
          setServerErrors(fieldErrorsOf(error))
          return
        }
        setBanner(authErrorMessage(error))
        return
      }
      toast.success(copy.auth.resetPassword.success)
      await navigate({ to: '/$workspace/login', params: { workspace }, search: {}, replace: true })
    },
  })

  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
      className="flex flex-col gap-5"
    >
      {banner ? <AuthErrorBanner title={copy.auth.resetPassword.failed} message={banner} /> : null}

      <FieldGroup>
        <TextField
          id="reset-workspace"
          label={copy.auth.workspaceLabel}
          value={workspace}
          onValueChange={() => undefined}
          readOnly
          errors={serverErrors.workspace}
        />

        <form.Field name="email">
          {(field) => (
            <TextField
              id="reset-email"
              label={copy.auth.emailLabel}
              type="email"
              autoComplete="username"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.email)}
            />
          )}
        </form.Field>

        <form.Field name="password">
          {(field) => (
            <TextField
              id="reset-password"
              label={copy.auth.passwordLabel}
              type="password"
              autoComplete="new-password"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.password)}
            />
          )}
        </form.Field>

        <form.Field name="password_confirmation">
          {(field) => (
            <TextField
              id="reset-password-confirmation"
              label={copy.auth.passwordConfirmationLabel}
              type="password"
              autoComplete="new-password"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.password_confirmation)}
            />
          )}
        </form.Field>
      </FieldGroup>

      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? copy.auth.resetPassword.submitting : copy.auth.resetPassword.submit}
          </Button>
        )}
      </form.Subscribe>

      <BackToLogin workspace={workspace} />
    </form>
  )
}

import { revalidateLogic, useForm } from '@tanstack/react-form'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArrowLeftIcon, MailCheckIcon } from 'lucide-react'
import { useState } from 'react'
import { PasswordField } from '@/components/shared/password-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { requestPasswordReset, resetPassword } from '../api/auth-requests'
import { authErrorMessage, fieldErrorsOf, isFieldError } from '../auth-errors'
import { forgotPasswordSchema, resetPasswordSchema } from '../schemas'
import { AuthErrorBanner } from './auth-error-banner'
import { SentPanel } from './sent-panel'

/** A 422 on `workspace` (the slug in the URL) has no field to sit on, so it is shown as a banner. */
function WorkspaceErrors({ errors, title }: { errors?: string[]; title: string }) {
  return errors && errors.length > 0 ? <AuthErrorBanner title={title} message={errors.join(' ')} /> : null
}

function BackToLogin({ workspace }: { workspace: string }) {
  return (
    <Link
      to="/$workspace/login"
      params={{ workspace }}
      search={{}}
      className="inline-flex items-center gap-1.5 self-center text-muted-foreground text-sm hover:text-foreground"
    >
      <ArrowLeftIcon aria-hidden="true" className="size-4" />
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
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: '' },
    // Checked on submit, then again as each field changes (the app-wide form timing, M4-13).
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: forgotPasswordSchema },
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
      setSentTo(value.email.trim())
    },
  })

  if (sentTo) {
    return (
      <div className="flex flex-col gap-6">
        <SentPanel
          icon={MailCheckIcon}
          title={copy.auth.forgotPassword.sentHeading}
          body={fill(copy.auth.forgotPassword.sentBody, { email: sentTo, workspace })}
        />
        <Button
          type="button"
          variant="outline"
          size="lg"
          onClick={() => {
            form.reset()
            setSentTo(null)
          }}
        >
          {copy.auth.forgotPassword.tryAnother}
        </Button>
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

      <WorkspaceErrors errors={serverErrors.workspace} title={copy.auth.forgotPassword.failed} />

      <FieldGroup>
        <form.Field name="email">
          {(field) => (
            <TextField
              id="forgot-email"
              label={copy.auth.emailLabel}
              type="email"
              autoComplete="username"
              autoFocus
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
    // Checked on submit, then again as each field changes (the app-wide form timing, M4-13).
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: resetPasswordSchema },
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

      <WorkspaceErrors errors={serverErrors.workspace} title={copy.auth.resetPassword.failed} />

      <FieldGroup>
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
            <PasswordField
              id="reset-password"
              label={copy.auth.passwordLabel}
              description={copy.auth.password.rules}
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
            <PasswordField
              id="reset-password-confirmation"
              label={copy.auth.passwordConfirmationLabel}
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

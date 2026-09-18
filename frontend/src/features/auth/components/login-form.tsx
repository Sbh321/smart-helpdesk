import { useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { useState } from 'react'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy } from '@/copy/en'
import { afterSignInHref } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { login } from '../api/auth-requests'
import { reloadSession } from '../api/session-queries'
import { authErrorMessage, fieldErrorsOf, isFieldError } from '../auth-errors'
import { loginSchema } from '../schemas'
import { AuthErrorBanner } from './auth-error-banner'

/**
 * Sign-in for one workspace (docs/07-api/authentication.md §1). The workspace comes from the URL path
 * segment and is shown read-only: it is a body field of `POST /v1/auth/login`, never a tenant header.
 */
export function LoginForm({ workspace, redirect }: { workspace: string; redirect?: string }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [banner, setBanner] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: '', password: '' },
    validators: { onSubmit: loginSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await login({ workspace, email: value.email, password: value.password })
      } catch (error) {
        if (isFieldError(error)) {
          setServerErrors(fieldErrorsOf(error))
          return
        }
        setBanner(authErrorMessage(error))
        return
      }
      const session = await reloadSession(queryClient)
      if (!session) {
        setBanner(copy.auth.errors.unexpected)
        return
      }
      await navigate({ href: afterSignInHref(session, redirect), replace: true })
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
      {banner ? <AuthErrorBanner title={copy.auth.login.failed} message={banner} /> : null}

      <FieldGroup>
        <TextField
          id="login-workspace"
          label={copy.auth.workspaceLabel}
          value={workspace}
          onValueChange={() => undefined}
          readOnly
          autoComplete="organization"
          errors={serverErrors.workspace}
        />

        <form.Field name="email">
          {(field) => (
            <TextField
              id="login-email"
              label={copy.auth.emailLabel}
              type="email"
              autoComplete="username"
              // The sign-in page exists to receive this one entry, so the cursor starts here.
              autoFocus
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
              id="login-password"
              label={copy.auth.passwordLabel}
              type="password"
              autoComplete="current-password"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.password)}
            />
          )}
        </form.Field>
      </FieldGroup>

      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? copy.auth.login.submitting : copy.auth.login.submit}
          </Button>
        )}
      </form.Subscribe>

      <div className="flex flex-wrap justify-between gap-2 text-sm">
        <Link
          to="/$workspace/reset-password"
          params={{ workspace }}
          search={{}}
          className="underline underline-offset-4 hover:text-primary"
        >
          {copy.auth.login.forgot}
        </Link>
        <Link to="/" className="text-muted-foreground underline underline-offset-4 hover:text-primary">
          {copy.auth.login.changeWorkspace}
        </Link>
      </div>
    </form>
  )
}

import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { useState } from 'react'
import { PasswordField } from '@/components/shared/password-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { copy } from '@/copy/en'
import { AuthErrorBanner, authErrorMessage, loginSchema } from '@/features/auth'
import { isApiError } from '@/lib/api/errors'
import { sanitiseRedirect } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { platformLogin, reloadPlatformSession } from '../api'

/** Only console paths are followed after sign-in, so a crafted `?redirect=` cannot leave the admin host. */
export function platformRedirect(value: string | undefined): string | undefined {
  const path = sanitiseRedirect(value)
  return path?.startsWith('/platform/') && path !== '/platform/login' ? path : undefined
}

/** Sign-in for Platform Super Admins on the admin host (`POST /platform-api/auth/login`). */
export function PlatformLoginForm({ redirect }: { redirect?: string }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [banner, setBanner] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: '', password: '' },
    // Checked on submit, then again as each field changes (the app-wide form timing, M4-13).
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: loginSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await platformLogin({ email: value.email, password: value.password })
      } catch (error) {
        if (isApiError(error) && error.isValidation) {
          setServerErrors(error.fieldErrors)
          return
        }
        // The shared wording names the workspace, which the platform sign-in does not have.
        setBanner(
          isApiError(error) && error.code === 'invalid_credentials'
            ? copy.platform.login.invalidCredentials
            : authErrorMessage(error),
        )
        return
      }
      if (!(await reloadPlatformSession(queryClient))) {
        setBanner(copy.auth.errors.unexpected)
        return
      }
      // Back to the console page that sent the admin here (the platform docs hand-off, M5-06), else the tenants.
      await navigate({ href: platformRedirect(redirect) ?? '/platform', replace: true })
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
        <form.Field name="email">
          {(field) => (
            <TextField
              id="platform-login-email"
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
            <PasswordField
              id="platform-login-password"
              label={copy.auth.passwordLabel}
              autoComplete="current-password"
              action={
                <Link to="/platform/forgot-password" className="text-primary text-sm hover:underline">
                  {copy.platform.login.forgot}
                </Link>
              }
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
    </form>
  )
}

import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { useState } from 'react'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { workspaceHref } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { acceptInvitation } from '../api/auth-requests'
import { reloadSession } from '../api/session-queries'
import { authErrorMessage, fieldErrorsOf, isFieldError } from '../auth-errors'
import { acceptInvitationSchema } from '../schemas'
import { AuthErrorBanner } from './auth-error-banner'

/**
 * Finishes an invitation (docs/07-api/authentication.md §2). The token comes from the link's `token`
 * search param; the workspace from the path segment must match the token's tenant, which the API checks.
 */
export function AcceptInvitationForm({ workspace, token }: { workspace: string; token: string }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [banner, setBanner] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { name: '', password: '', password_confirmation: '' },
    // Checked on submit, then again as each field changes (the app-wide form timing, M4-13).
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: acceptInvitationSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await acceptInvitation(token, {
          workspace,
          password: value.password,
          password_confirmation: value.password_confirmation,
          ...(value.name.length > 0 ? { name: value.name } : {}),
        })
      } catch (error) {
        if (isFieldError(error)) {
          setServerErrors(fieldErrorsOf(error))
          return
        }
        setBanner(authErrorMessage(error))
        return
      }
      toast.success(copy.auth.acceptInvitation.success)
      // The API may or may not start a session here, so ask rather than assume.
      const session = await reloadSession(queryClient)
      await (session
        ? navigate({ href: workspaceHref(session), replace: true })
        : navigate({ to: '/$workspace/login', params: { workspace }, search: {}, replace: true }))
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
      {banner ? <AuthErrorBanner title={copy.auth.acceptInvitation.failed} message={banner} /> : null}

      <FieldGroup>
        <TextField
          id="invitation-workspace"
          label={copy.auth.workspaceLabel}
          value={workspace}
          onValueChange={() => undefined}
          readOnly
          errors={serverErrors.workspace}
        />

        <form.Field name="name">
          {(field) => (
            <TextField
              id="invitation-name"
              label={copy.auth.nameLabel}
              autoComplete="name"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, serverErrors.name)}
            />
          )}
        </form.Field>

        <form.Field name="password">
          {(field) => (
            <TextField
              id="invitation-password"
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
              id="invitation-password-confirmation"
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
            {isSubmitting ? copy.auth.acceptInvitation.submitting : copy.auth.acceptInvitation.submit}
          </Button>
        )}
      </form.Subscribe>
    </form>
  )
}

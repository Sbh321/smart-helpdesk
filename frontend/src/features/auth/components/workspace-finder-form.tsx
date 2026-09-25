import { revalidateLogic, useForm } from '@tanstack/react-form'
import { Link } from '@tanstack/react-router'
import { ArrowLeftIcon, MailCheckIcon } from 'lucide-react'
import { useState } from 'react'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { requestWorkspaceReminder } from '../api/auth-requests'
import { authErrorMessage, fieldErrorsOf, isFieldError } from '../auth-errors'
import { workspaceFinderSchema } from '../schemas'
import { AuthErrorBanner } from './auth-error-banner'
import { SentPanel } from './sent-panel'

function BackToEntry() {
  return (
    <Link
      to="/"
      search={{}}
      className="inline-flex items-center gap-1.5 self-center text-muted-foreground text-sm hover:text-foreground"
    >
      <ArrowLeftIcon aria-hidden="true" className="size-4" />
      {copy.workspaceFinder.back}
    </Link>
  )
}

/**
 * "Find it by email" (M5-02, M5-03). The API answers the same for every address, so the confirmation
 * says "if", names the address the visitor typed, and offers to try another.
 */
export function WorkspaceFinderForm() {
  const [banner, setBanner] = useState<string | null>(null)
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({})

  const form = useForm({
    defaultValues: { email: '' },
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: workspaceFinderSchema },
    onSubmit: async ({ value }) => {
      setBanner(null)
      setServerErrors({})
      try {
        await requestWorkspaceReminder({ email: value.email.trim() })
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
          title={copy.workspaceFinder.sentHeading}
          body={fill(copy.workspaceFinder.sentBody, { email: sentTo })}
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
          {copy.workspaceFinder.tryAnother}
        </Button>
        <BackToEntry />
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
      {banner ? <AuthErrorBanner title={copy.workspaceFinder.failed} message={banner} /> : null}
      <form.Field name="email">
        {(field) => (
          <TextField
            id="finder-email"
            label={copy.auth.emailLabel}
            type="email"
            autoComplete="email"
            autoFocus
            value={field.state.value}
            onValueChange={field.handleChange}
            onBlur={field.handleBlur}
            errors={mergeMessages(field.state.meta.errors, serverErrors.email)}
          />
        )}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? copy.workspaceFinder.submitting : copy.workspaceFinder.submit}
          </Button>
        )}
      </form.Subscribe>
      <BackToEntry />
    </form>
  )
}

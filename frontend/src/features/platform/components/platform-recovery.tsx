import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { CircleCheckIcon, MailCheckIcon } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PasswordField } from '@/components/shared/password-field'
import { TextField } from '@/components/shared/text-field'
import { Button, buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { SentPanel } from '@/features/auth'
import { queryKeys } from '@/lib/api/query-keys'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { platform } from '../api'

const text = copy.platform.recovery

function BackToSignIn() {
  return (
    <Link to="/platform/login" className="text-primary text-sm hover:underline">
      {text.backToSignIn}
    </Link>
  )
}

/** "Forgot your password?": the same answer for every address (ADR-0025 §7). */
export function PlatformForgotPasswordForm() {
  const server = useServerErrors()
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (sent) {
    return (
      <div className="flex flex-col gap-4">
        <SentPanel icon={MailCheckIcon} title={text.sentTitle} body={fill(text.sentBody, { email: sent })} />
        <BackToSignIn />
      </div>
    )
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.forgotPassword(email.trim())
      setSent(email.trim())
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-5">
      {server.failure ? (
        <FormErrorBanner title={copy.platform.account.failed} error={server.failure} />
      ) : null}
      <TextField
        id="forgot-email"
        label={copy.auth.emailLabel}
        type="email"
        autoComplete="username"
        autoFocus
        value={email}
        onValueChange={setEmail}
        errors={server.fields.email}
      />
      <Button type="submit" size="lg" disabled={busy}>
        {text.send}
      </Button>
      <BackToSignIn />
    </form>
  )
}

/** A new password from the emailed link. */
export function PlatformResetPasswordForm({ token, email }: { token: string; email: string }) {
  const server = useServerErrors()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)

  if (!token || !email) return <p className="text-destructive text-sm">{text.invalidLink}</p>
  if (done) {
    return (
      <div className="flex flex-col gap-4">
        <SentPanel icon={CircleCheckIcon} title={text.doneTitle} body={text.doneBody} />
        <Link to="/platform/login" className={buttonVariants({ size: 'lg' })}>
          {text.toSignIn}
        </Link>
      </div>
    )
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.resetPassword({ token, email, password, password_confirmation: confirm })
      setDone(true)
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-5">
      {server.failure ? (
        <FormErrorBanner title={copy.platform.account.failed} error={server.failure} />
      ) : null}
      <PasswordField
        id="reset-password"
        label={copy.platform.account.newPassword}
        autoComplete="new-password"
        autoFocus
        value={password}
        onValueChange={setPassword}
        errors={server.fields.password}
      />
      <PasswordField
        id="reset-confirm"
        label={copy.platform.account.confirm}
        autoComplete="new-password"
        value={confirm}
        onValueChange={setConfirm}
      />
      <Button type="submit" size="lg" disabled={busy}>
        {text.reset}
      </Button>
      <BackToSignIn />
    </form>
  )
}

/** Accepting an admin invitation: a name and a password, then straight into the console. */
export function PlatformAcceptInvitation({ token }: { token: string }) {
  const invitation = useQuery({
    queryKey: [...queryKeys.platform.all, 'invitation', token],
    queryFn: () => platform.invitation(token),
    retry: false,
    enabled: token !== '',
  })
  if (token === '') return <p className="text-destructive text-sm">{text.invalidLink}</p>
  if (invitation.isPending) {
    return (
      <div aria-busy="true" className="flex flex-col gap-3">
        <p role="status" className="sr-only">
          {copy.platform.invitation.loading}
        </p>
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    )
  }
  if (invitation.isError) {
    return (
      <div className="flex flex-col gap-4">
        <ErrorState error={invitation.error} title={copy.platform.invitation.expiredTitle} />
        <BackToSignIn />
      </div>
    )
  }
  return <AcceptForm token={token} email={invitation.data.email} initialName={invitation.data.name} />
}

function AcceptForm({ token, email, initialName }: { token: string; email: string; initialName: string }) {
  const client = useQueryClient()
  const navigate = useNavigate()
  const server = useServerErrors()
  const [name, setName] = useState(initialName)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.acceptInvitation(token, { name: name.trim(), password, password_confirmation: confirm })
      await client.invalidateQueries({ queryKey: queryKeys.platform.all })
      await navigate({ to: '/platform', replace: true })
    } catch (error) {
      server.capture(error)
      setBusy(false)
    }
  }
  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-5">
      <p className="text-muted-foreground text-sm">{fill(copy.platform.invitation.body, { email })}</p>
      {server.failure ? (
        <FormErrorBanner title={copy.platform.account.failed} error={server.failure} />
      ) : null}
      <TextField
        id="accept-name"
        label={copy.platform.account.name}
        autoComplete="name"
        value={name}
        onValueChange={setName}
        errors={server.fields.name}
      />
      <PasswordField
        id="accept-password"
        label={copy.platform.account.newPassword}
        autoComplete="new-password"
        value={password}
        onValueChange={setPassword}
        errors={server.fields.password}
      />
      <PasswordField
        id="accept-confirm"
        label={copy.platform.account.confirm}
        autoComplete="new-password"
        value={confirm}
        onValueChange={setConfirm}
      />
      <Button type="submit" size="lg" disabled={busy}>
        {copy.platform.invitation.accept}
      </Button>
    </form>
  )
}

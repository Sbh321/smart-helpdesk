import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { CircleCheckIcon, LoaderCircleIcon, MailCheckIcon } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PasswordField } from '@/components/shared/password-field'
import { TextField } from '@/components/shared/text-field'
import { TimeZoneField } from '@/components/shared/time-zone-field'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { SentPanel } from '@/features/auth'
import { isApiError } from '@/lib/api/errors'
import { useRuntimeConfig } from '@/lib/config'
import { browserTimeZone } from '@/lib/datetime/time-zones'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { useDebouncedValue } from '@/lib/use-debounced-value'
import { signupApi } from '../api'

const text = copy.signup

/** "Acme Support Ltd." → "acme-support-ltd". */
function slugFrom(name: string): string {
  return name
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
    .replace(/-+$/, '')
}

/**
 * Self sign-up (ADR-0025 §8): who you are, a password, the workspace and its address (checked as you
 * type). Nothing is created until the emailed link is followed.
 */
export function SignupForm() {
  const { platformDomain } = useRuntimeConfig()
  const server = useServerErrors()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [workspaceName, setWorkspaceName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
  const [timezone, setTimezone] = useState(browserTimeZone)
  const [website, setWebsite] = useState('')
  const [busy, setBusy] = useState(false)
  const [sent, setSent] = useState<{ email: string; workspace: string } | null>(null)
  const address = slug || slugFrom(workspaceName)
  const checked = useDebouncedValue(address, 300)
  const availability = useQuery({
    queryKey: ['signup', 'address', checked],
    queryFn: () => signupApi.address(checked),
    enabled: checked.length > 0,
    staleTime: 10_000,
  })

  if (sent) {
    return (
      <div className="flex flex-col gap-4">
        <SentPanel icon={MailCheckIcon} title={text.sentTitle} body={fill(text.sentBody, sent)} />
        <Button type="button" variant="outline" onClick={() => setSent(null)}>
          {text.useAnother}
        </Button>
      </div>
    )
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await signupApi.request({
        name: name.trim(),
        email: email.trim(),
        password,
        password_confirmation: confirm,
        workspace_name: workspaceName.trim(),
        slug: address,
        timezone,
        website,
      })
      setSent({ email: email.trim(), workspace: workspaceName.trim() })
    } catch (error) {
      server.capture(isApiError(error) && error.code === 'signup_closed' ? new Error(text.closed) : error)
    } finally {
      setBusy(false)
    }
  }

  const status = availability.data
  const addressErrors =
    server.fields.slug ??
    (status && status.slug === address && !status.available && status.reason
      ? [text.reasons[status.reason]]
      : undefined)

  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-5">
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="signup-name"
          label={text.name}
          autoComplete="name"
          value={name}
          onValueChange={setName}
          errors={server.fields.name}
          autoFocus
        />
        <TextField
          id="signup-email"
          label={text.email}
          type="email"
          autoComplete="email"
          value={email}
          onValueChange={setEmail}
          errors={server.fields.email}
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <PasswordField
          id="signup-password"
          label={text.password}
          autoComplete="new-password"
          value={password}
          onValueChange={setPassword}
          errors={server.fields.password}
        />
        <PasswordField
          id="signup-confirm"
          label={copy.platform.account.confirm}
          autoComplete="new-password"
          value={confirm}
          onValueChange={setConfirm}
        />
      </div>
      <TextField
        id="signup-workspace"
        label={text.workspaceName}
        description={text.workspaceNameHint}
        autoComplete="organization"
        value={workspaceName}
        onValueChange={(value) => {
          setWorkspaceName(value)
          if (!slugEdited) setSlug(slugFrom(value))
        }}
        errors={server.fields.workspace_name}
      />
      <div className="flex flex-col gap-1.5">
        <TextField
          id="signup-slug"
          label={text.slug}
          description={fill(text.slugHint, { url: `app.${platformDomain}/${address || '…'}` })}
          value={address}
          onValueChange={(value) => {
            setSlugEdited(true)
            setSlug(value.toLowerCase())
          }}
          errors={addressErrors}
          autoComplete="off"
          spellCheck={false}
        />
        <p aria-live="polite" className="flex min-h-5 items-center gap-1.5 text-xs">
          {address && availability.isFetching ? (
            <>
              <LoaderCircleIcon
                aria-hidden="true"
                className="size-3.5 text-muted-foreground motion-safe:animate-spin"
              />
              <span className="text-muted-foreground">{text.checking}</span>
            </>
          ) : status?.available && status.slug === address ? (
            <>
              <CircleCheckIcon aria-hidden="true" className="size-3.5 text-success" />
              <span className="text-success">{text.available}</span>
            </>
          ) : null}
        </p>
      </div>
      <TimeZoneField
        id="signup-timezone"
        label={text.timezone}
        value={timezone}
        onValueChange={setTimezone}
        errors={server.fields.timezone}
      />
      {/* The honeypot: hidden from people and from assistive technology; form-filling robots fill it in. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label htmlFor="signup-website">{text.honeypot}</label>
        <input
          id="signup-website"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          value={website}
          onChange={(event) => setWebsite(event.target.value)}
        />
      </div>
      <p className="text-muted-foreground text-xs">{text.terms}</p>
      <Button type="submit" size="lg" disabled={busy}>
        {busy ? text.submitting : text.submit}
      </Button>
      <p className="text-center text-muted-foreground text-sm">
        {text.haveWorkspace}{' '}
        <Link to="/" className="font-medium text-primary underline underline-offset-4">
          {text.signIn}
        </Link>
      </p>
    </form>
  )
}

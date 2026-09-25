import { useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { PasswordField } from '@/components/shared/password-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { platform, platformSessionQuery } from '../api'

const text = copy.platform.account

/** The signed-in admin's own name and password (ADR-0025 §7). */
export function AccountScreen() {
  const admin = useQuery(platformSessionQuery()).data
  return (
    <>
      <PageHeader title={text.title} description={text.description} />
      <div className="grid max-w-4xl gap-6 lg:grid-cols-2">
        {admin ? <ProfileForm name={admin.name} email={admin.email} /> : null}
        <PasswordForm />
      </div>
    </>
  )
}

function ProfileForm({ name: initial, email }: { name: string; email: string }) {
  const client = useQueryClient()
  const server = useServerErrors()
  const [name, setName] = useState(initial)
  const [busy, setBusy] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.updateProfile({ name: name.trim() })
      await client.invalidateQueries({ queryKey: queryKeys.platform.me() })
      toast.success(text.profileSaved)
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }
  return (
    <form
      noValidate
      onSubmit={(event) => void submit(event)}
      aria-labelledby="account-profile"
      className="flex flex-col gap-4 rounded-card border border-border bg-surface p-4 shadow-1"
    >
      <h2 id="account-profile" className="font-semibold text-base">
        {text.profile}
      </h2>
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <TextField
        id="account-name"
        label={text.name}
        value={name}
        onValueChange={setName}
        errors={server.fields.name}
        autoComplete="name"
      />
      <TextField
        id="account-email"
        label={text.email}
        value={email}
        onValueChange={() => undefined}
        readOnly
        disabled
      />
      <div>
        <Button type="submit" disabled={busy}>
          {text.saveProfile}
        </Button>
      </div>
    </form>
  )
}

function PasswordForm() {
  const server = useServerErrors()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.changePassword({
        current_password: current,
        password: next,
        password_confirmation: confirm,
      })
      setCurrent('')
      setNext('')
      setConfirm('')
      toast.success(text.passwordSaved)
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }
  return (
    <form
      noValidate
      onSubmit={(event) => void submit(event)}
      aria-labelledby="account-password"
      className="flex flex-col gap-4 rounded-card border border-border bg-surface p-4 shadow-1"
    >
      <h2 id="account-password" className="font-semibold text-base">
        {text.password}
      </h2>
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <PasswordField
        id="account-current"
        label={text.current}
        value={current}
        onValueChange={setCurrent}
        errors={server.fields.current_password}
        autoComplete="current-password"
      />
      <PasswordField
        id="account-new"
        label={text.newPassword}
        value={next}
        onValueChange={setNext}
        errors={server.fields.password}
        autoComplete="new-password"
      />
      <PasswordField
        id="account-confirm"
        label={text.confirm}
        value={confirm}
        onValueChange={setConfirm}
        autoComplete="new-password"
      />
      <div>
        <Button type="submit" disabled={busy}>
          {text.savePassword}
        </Button>
      </div>
    </form>
  )
}

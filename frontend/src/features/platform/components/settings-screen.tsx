import { useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, useEffect, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Switch } from '@/components/ui/switch'
import { copy } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { platform, platformSettingsQuery } from '../api'

const text = copy.platform.settings

/** Platform settings (ADR-0025 §6, §8): whether people can sign up, and the grace period. */
export function PlatformSettingsScreen() {
  const settings = useQuery(platformSettingsQuery())
  const client = useQueryClient()
  const server = useServerErrors()
  const [signup, setSignup] = useState(true)
  const [grace, setGrace] = useState('7')
  const [instructions, setInstructions] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (settings.data) {
      setSignup(settings.data.signup_enabled)
      setGrace(String(settings.data.grace_days))
      setInstructions(settings.data.payment_instructions)
    }
  }, [settings.data])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.updateSettings({
        signup_enabled: signup,
        grace_days: Number(grace),
        payment_instructions: instructions,
      })
      await client.invalidateQueries({ queryKey: queryKeys.platform.settings() })
      toast.success(text.saved)
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader title={text.title} description={text.description} />
      {settings.isPending ? (
        <Skeleton className="h-40 w-full max-w-2xl" />
      ) : settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />
      ) : (
        <form noValidate onSubmit={(event) => void submit(event)} className="flex max-w-2xl flex-col gap-6">
          {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
          <section
            aria-labelledby="settings-signup"
            className="flex flex-col gap-3 rounded-card border border-border bg-surface p-4 shadow-1"
          >
            <h2 id="settings-signup" className="font-semibold text-base">
              {text.signup}
            </h2>
            <div className="flex items-start gap-3">
              <Switch
                id="signup-enabled"
                checked={signup}
                onCheckedChange={setSignup}
                aria-describedby="signup-hint"
              />
              <div className="flex flex-col gap-1">
                <Label htmlFor="signup-enabled">{text.signupLabel}</Label>
                <p id="signup-hint" className="text-muted-foreground text-xs">
                  {text.signupHint}
                </p>
              </div>
            </div>
          </section>
          <section
            aria-labelledby="settings-grace"
            className="flex flex-col gap-3 rounded-card border border-border bg-surface p-4 shadow-1"
          >
            <h2 id="settings-grace" className="font-semibold text-base">
              {text.grace}
            </h2>
            <TextField
              id="grace-days"
              label={text.graceLabel}
              description={text.graceHint}
              type="number"
              min={0}
              max={60}
              value={grace}
              onValueChange={setGrace}
              errors={server.fields.grace_days}
              className="max-w-32"
            />
          </section>
          <section
            aria-labelledby="settings-payments"
            className="flex flex-col gap-3 rounded-card border border-border bg-surface p-4 shadow-1"
          >
            <h2 id="settings-payments" className="font-semibold text-base">
              {text.payments}
            </h2>
            <TextareaField
              id="payment-instructions"
              label={text.instructionsLabel}
              description={text.instructionsHint}
              value={instructions}
              onValueChange={setInstructions}
              errors={server.fields.payment_instructions}
              rows={5}
            />
          </section>
          <div>
            <Button type="submit" disabled={busy}>
              {text.save}
            </Button>
          </div>
        </form>
      )}
    </>
  )
}

import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { Switch } from '@/components/ui/switch'
import { copy } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { saveSettings } from '../api/settings-queries'
import { type SlaDefaultsFormValues, slaDefaultsFormSchema } from '../schemas'
import { type LoadedSection, SettingsSectionScreen } from './settings-section-screen'

const text = copy.workspaceSettings
const labels = text.slaForm

function toFormValues(values: LoadedSection<'sla'>['values']): SlaDefaultsFormValues {
  return {
    warning_fraction: String(values.warning_fraction),
    first_response_applies_to_agent_created: values.first_response_applies_to_agent_created,
  }
}

function SlaDefaultsForm({ tenantId, values, defaults }: LoadedSection<'sla'>) {
  const client = useQueryClient()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: toFormValues(values),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: slaDefaultsFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await saveSettings(client, tenantId, 'sla', {
          warning_fraction: Number(value.warning_fraction),
          first_response_applies_to_agent_created: value.first_response_applies_to_agent_created,
        })
        toast.success(text.saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={labels.title}
      className="flex max-w-xl flex-col gap-5 rounded-lg border border-border p-4"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <form.Field name="warning_fraction">
        {(field) => (
          <TextField
            id="settings-sla-warning-fraction"
            label={labels.warningFraction}
            description={labels.warningFractionHelp}
            type="number"
            inputMode="decimal"
            min={0.1}
            max={0.95}
            step={0.05}
            value={field.state.value}
            onValueChange={field.handleChange}
            onBlur={field.handleBlur}
            errors={mergeMessages(field.state.meta.errors, server.exact.warning_fraction)}
          />
        )}
      </form.Field>
      <form.Field name="first_response_applies_to_agent_created">
        {(field) => (
          <div className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <p id="settings-sla-first-response-label" className="text-sm font-medium">
                {labels.firstResponse}
              </p>
              <p id="settings-sla-first-response-description" className="text-sm text-muted-foreground">
                {labels.firstResponseHelp}
              </p>
            </div>
            <Switch
              id="settings-sla-first-response"
              aria-labelledby="settings-sla-first-response-label"
              aria-describedby="settings-sla-first-response-description"
              checked={field.state.value}
              onCheckedChange={field.handleChange}
            />
          </div>
        )}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? text.saving : text.save}
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => {
                const next = toFormValues(defaults)
                form.setFieldValue('warning_fraction', next.warning_fraction)
                form.setFieldValue(
                  'first_response_applies_to_agent_created',
                  next.first_response_applies_to_agent_created,
                )
              }}
            >
              {text.resetDefaults}
            </Button>
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}

/** The `sla` section, shown under the policies on Settings → SLA policies to whoever has `settings.manage`. */
export function SlaDefaultsCard() {
  return (
    <SettingsSectionScreen section="sla" title={labels.title} hideWhenForbidden>
      {(loaded) => <SlaDefaultsForm {...loaded} />}
    </SettingsSectionScreen>
  )
}

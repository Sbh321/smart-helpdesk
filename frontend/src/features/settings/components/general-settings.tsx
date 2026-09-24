import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SaveBar, UnsavedChangesGuard } from '@/components/shared/save-bar'
import { TextField } from '@/components/shared/text-field'
import { TimeZoneField } from '@/components/shared/time-zone-field'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { saveSettings } from '../api/settings-queries'
import { generalFormSchema } from '../schemas'
import { type LoadedSection, SettingsSectionScreen } from './settings-section-screen'

const text = copy.workspaceSettings

function GeneralForm({ tenantId, values }: LoadedSection<'general'>) {
  const client = useQueryClient()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: { name: values.name, timezone: values.timezone },
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: generalFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await saveSettings(client, tenantId, 'general', {
          name: value.name.trim(),
          timezone: value.timezone.trim(),
        })
        // What was saved is the new baseline: the form is clean again.
        form.reset({ name: value.name.trim(), timezone: value.timezone.trim() })
        toast.success(text.saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={text.general}
      className="flex max-w-xl flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="settings-general-name"
              label={text.generalForm.name}
              autoComplete="organization"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="timezone">
          {(field) => (
            <TimeZoneField
              id="settings-general-timezone"
              label={text.generalForm.timezone}
              description={text.generalForm.timezoneHelp}
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.timezone)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe
        selector={(state) => ({ dirty: !state.isDefaultValue, submitting: state.isSubmitting })}
      >
        {({ dirty, submitting }) => (
          <>
            <SaveBar
              dirty={dirty}
              submitting={submitting}
              saveLabel={text.save}
              onDiscard={() => form.reset()}
            />
            <UnsavedChangesGuard dirty={dirty} />
          </>
        )}
      </form.Subscribe>
    </form>
  )
}

export function GeneralSettings() {
  return (
    <SettingsSectionScreen section="general" title={text.general} intro={text.generalForm.intro}>
      {(loaded) => <GeneralForm {...loaded} />}
    </SettingsSectionScreen>
  )
}

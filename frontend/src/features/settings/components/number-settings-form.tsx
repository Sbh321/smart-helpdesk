import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import type { z } from 'zod'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SaveBar, UnsavedChangesGuard } from '@/components/shared/save-bar'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type SettingsSectionKey, saveSettings } from '../api/settings-queries'

const text = copy.workspaceSettings

export interface NumberSetting<TName extends string> {
  name: TName
  label: string
  description: string
  /** The API's dot path of this value inside the section, where a 422 names it. */
  errorKey: string
  min: number
  max: number
  step: number
}

/**
 * A settings form made only of number inputs (tickets, duplicate detection). Values are text in the
 * form and numbers in the PATCH body; "reset to defaults" fills in the code defaults without saving.
 */
export function NumberSettingsForm<TName extends string>({
  label,
  idPrefix,
  tenantId,
  section,
  settings,
  schema,
  values,
  defaults,
  toInput,
}: {
  label: string
  idPrefix: string
  tenantId: string
  section: SettingsSectionKey
  settings: readonly NumberSetting<TName>[]
  schema: z.ZodType<unknown, Record<TName, string>>
  values: Record<TName, number>
  defaults: Record<TName, number>
  toInput: (numbers: Record<TName, number>) => object
}) {
  const client = useQueryClient()
  const server = useServerErrors()
  const asText = (numbers: Record<TName, number>) =>
    Object.fromEntries(settings.map(({ name }) => [name, String(numbers[name])])) as Record<TName, string>
  const form = useForm({
    defaultValues: asText(values) as Record<string, string>,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: schema as z.ZodType<unknown, Record<string, string>> },
    onSubmit: async ({ value }) => {
      server.reset()
      const numbers = Object.fromEntries(settings.map(({ name }) => [name, Number(value[name])])) as Record<
        TName,
        number
      >
      try {
        await saveSettings(client, tenantId, section, toInput(numbers))
        form.reset(value)
        toast.success(text.saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={label}
      className="flex max-w-xl flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      {(server.exact.baseline ?? []).map((message) => (
        <p key={message} role="alert" className="text-sm text-destructive">
          {message}
        </p>
      ))}
      <FieldGroup>
        {settings.map((setting) => (
          <form.Field key={setting.name} name={setting.name as string}>
            {(field) => (
              <TextField
                id={`${idPrefix}-${setting.name}`}
                label={setting.label}
                description={setting.description}
                type="number"
                inputMode="decimal"
                min={setting.min}
                max={setting.max}
                step={setting.step}
                value={field.state.value ?? ''}
                onValueChange={field.handleChange}
                onBlur={field.handleBlur}
                errors={mergeMessages(field.state.meta.errors, server.exact[setting.errorKey])}
              />
            )}
          </form.Field>
        ))}
      </FieldGroup>
      <form.Subscribe
        selector={(state) => ({ dirty: !state.isDefaultValue, submitting: state.isSubmitting })}
      >
        {({ dirty, submitting }) => (
          <>
            {/* Fills in the code defaults without saving; the save bar then shows the change. */}
            <div>
              <Button
                type="button"
                variant="outline"
                disabled={submitting}
                onClick={() => {
                  const next = asText(defaults)
                  for (const { name } of settings) form.setFieldValue(name as string, next[name])
                }}
              >
                {text.resetDefaults}
              </Button>
            </div>
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

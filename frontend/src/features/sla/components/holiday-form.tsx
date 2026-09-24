import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { FormField } from '@/components/shared/form-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { addHoliday, type Calendar } from '../api/sla-queries'
import { type HolidayFormValues, holidayFormSchema } from '../schemas'

export function HolidayForm({
  calendar,
  onDone,
}: {
  calendar: Pick<Calendar, 'id' | 'name'>
  onDone: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const defaultValues: HolidayFormValues = { date: '', name: '', recurs_yearly: false }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: holidayFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await addHoliday(calendar.id, { ...value, name: value.name.trim() })
        await client.invalidateQueries({ queryKey: queryKeys.sla.all(tenantId) })
        toast.success(copy.sla.holidaySaved)
        onDone()
      } catch (error) {
        server.capture(error)
      }
    },
  })
  const prefix = `holiday-${calendar.id}`

  return (
    <form
      noValidate
      aria-label={fill(copy.sla.addHolidayTo, { name: calendar.name })}
      className="flex flex-col gap-4 rounded-md border border-border p-3"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.sla.holidayFailed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="date">
          {(field) => (
            <TextField
              id={`${prefix}-date`}
              label={copy.sla.date}
              type="date"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.date)}
            />
          )}
        </form.Field>
        <form.Field name="name">
          {(field) => (
            <TextField
              id={`${prefix}-name`}
              label={copy.settings.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="recurs_yearly">
          {(field) => (
            <FormField
              id={`${prefix}-recurs`}
              label={copy.sla.annual}
              errors={mergeMessages(field.state.meta.errors, server.fields.recurs_yearly)}
            >
              {(control) => (
                <Checkbox
                  {...control}
                  checked={field.state.value}
                  onCheckedChange={(checked) => field.handleChange(checked === true)}
                />
              )}
            </FormField>
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? copy.sla.saving : copy.sla.saveHoliday}
            </Button>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={onDone}>
              {copy.sla.cancel}
            </Button>
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}

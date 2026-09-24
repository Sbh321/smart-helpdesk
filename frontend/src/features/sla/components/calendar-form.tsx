import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { UnsavedChangesGuard } from '@/components/shared/save-bar'
import { TextField } from '@/components/shared/text-field'
import { TimeZoneField } from '@/components/shared/time-zone-field'
import { Button } from '@/components/ui/button'
import { FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type Calendar, type CalendarInput, createCalendar, updateCalendar } from '../api/sla-queries'
import { type CalendarFormValues, calendarFormSchema } from '../schemas'
import { DAYS, emptyWeek, newWindow, toWeeklyHours, toWeekRows } from '../weekly-hours'

/** A list response types the pairs as `string[][]`; `toWeekRows` accepts both. */
export type EditableCalendar = Pick<Calendar, 'id' | 'name' | 'timezone'> & {
  weekly_hours: Parameters<typeof toWeekRows>[0]
}

function initialValues(calendar: EditableCalendar | null, timezone: string): CalendarFormValues {
  return calendar
    ? { name: calendar.name, timezone: calendar.timezone, windows: toWeekRows(calendar.weekly_hours) }
    : { name: '', timezone, windows: { ...emptyWeek(), mon: [newWindow()] } }
}

function toInput(values: CalendarFormValues): CalendarInput {
  return {
    name: values.name.trim(),
    timezone: values.timezone.trim(),
    weekly_hours: toWeeklyHours(values.windows),
  }
}

const PARTS = [
  { name: 'start', label: copy.sla.startsAt, pair: 0 },
  { name: 'end', label: copy.sla.endsAt, pair: 1 },
] as const

export function CalendarForm({
  calendar,
  onDone,
}: {
  calendar: EditableCalendar | null
  onDone: () => void
}) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: initialValues(calendar, session?.tenant.timezone ?? 'UTC'),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: calendarFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        await (calendar ? updateCalendar(calendar.id, toInput(value)) : createCalendar(toInput(value)))
        await client.invalidateQueries({ queryKey: queryKeys.sla.all(tenantId) })
        toast.success(copy.sla.calendarSaved)
        onDone()
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={calendar ? fill(copy.sla.editNamed, { name: calendar.name }) : copy.sla.newCalendar}
      className="flex max-w-2xl flex-col gap-5 rounded-lg border border-border p-4"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.sla.calendarFailed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="calendar-name"
              label={copy.settings.name}
              autoComplete="off"
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
              id="calendar-zone"
              label={copy.sla.timeZone}
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.timezone)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <FieldSet>
        <FieldLegend>{copy.sla.weeklyHours}</FieldLegend>
        <form.Field name="windows">
          {(field) =>
            mergeMessages(field.state.meta.errors, server.exact.weekly_hours).map((message) => (
              <p key={message} role="alert" className="text-sm text-destructive">
                {message}
              </p>
            ))
          }
        </form.Field>
        {DAYS.map((day) => (
          <form.Field key={day} name={`windows.${day}`} mode="array">
            {(rows) => (
              <div className="grid gap-3 rounded-md border border-border p-3">
                <div className="flex items-center justify-between gap-2">
                  <h3 className="text-sm font-medium">{copy.sla.days[day]}</h3>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={fill(copy.sla.addWindowTo, { day: copy.sla.days[day] })}
                    onClick={() => rows.pushValue(newWindow())}
                  >
                    {copy.sla.addWindow}
                  </Button>
                </div>
                {rows.state.value.length === 0 ? (
                  <p className="text-sm text-muted-foreground">{copy.sla.closed}</p>
                ) : null}
                {rows.state.value.map((row, index) => (
                  <div key={row.id} className="grid items-start gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    {PARTS.map((part) => (
                      <form.Field key={part.name} name={`windows.${day}[${index}].${part.name}`}>
                        {(field) => (
                          <TextField
                            id={`${row.id}-${part.name}`}
                            label={fill(copy.sla.windowField, {
                              day: copy.sla.days[day],
                              number: index + 1,
                              part: part.label,
                            })}
                            type="time"
                            value={field.state.value}
                            onValueChange={field.handleChange}
                            onBlur={field.handleBlur}
                            errors={mergeMessages(
                              field.state.meta.errors,
                              server.exact[`weekly_hours.${day}.${index}.${part.pair}`],
                            )}
                          />
                        )}
                      </form.Field>
                    ))}
                    <Button
                      type="button"
                      variant="outline"
                      className="sm:mt-6"
                      aria-label={fill(copy.sla.removeWindowFrom, {
                        day: copy.sla.days[day],
                        number: index + 1,
                      })}
                      onClick={() => rows.removeValue(index)}
                    >
                      {copy.sla.remove}
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </form.Field>
        ))}
      </FieldSet>
      <form.Subscribe
        selector={(state) => ({ submitting: state.isSubmitting, dirty: !state.isDefaultValue })}
      >
        {({ submitting, dirty }) => (
          <div className="flex gap-2">
            <Button type="submit" disabled={submitting}>
              {submitting ? copy.sla.saving : copy.sla.saveCalendar}
            </Button>
            <Button type="button" variant="outline" disabled={submitting} onClick={onDone}>
              {copy.sla.cancel}
            </Button>
            <UnsavedChangesGuard dirty={dirty && !submitting} />
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}

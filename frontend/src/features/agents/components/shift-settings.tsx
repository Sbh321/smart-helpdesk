import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { FormField } from '@/components/shared/form-field'
import { SelectField } from '@/components/shared/select-field'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { copy, fill } from '@/copy/en'
import { ShiftEnforcementCard } from '@/features/settings'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type AgentShift, type AgentShiftInput, agentQueries, replaceAgentShifts } from '../api/agent-queries'
import { type ShiftsFormValues, shiftsFormSchema } from '../schemas'
import { PICKER_PAGE, Rows, Saved, Section, useDirectory } from './directory-shared'

type ShiftRow = ShiftsFormValues['shifts'][number]

/** `-1` stands for "no weekday": the row is a date exception. `Select` needs a non-null value. */
const NO_WEEKDAY = -1
const DAY_OPTIONS = [
  { value: NO_WEEKDAY, label: copy.settings.dateExceptionOption },
  ...copy.settings.weekdays.map((label, value) => ({ value, label })),
]

function toRows(shifts: AgentShift[]): ShiftRow[] {
  return shifts.map(({ id, weekday, date, starts_at, ends_at, is_off }) => ({
    key: id,
    weekday,
    date,
    starts_at,
    ends_at,
    is_off,
  }))
}

function toInput(values: ShiftsFormValues): AgentShiftInput {
  return {
    shifts: values.shifts.map(({ weekday, date, starts_at, ends_at, is_off }) => ({
      weekday,
      date,
      starts_at,
      ends_at,
      is_off,
    })),
  }
}

function shiftLabel(shift: Pick<AgentShift, 'weekday' | 'date' | 'starts_at' | 'ends_at' | 'is_off'>) {
  const time = fill(copy.settings.shiftRange, { start: shift.starts_at, end: shift.ends_at })
  return shift.is_off ? fill(copy.settings.shiftOff, { range: time }) : time
}

/** Mounted once the Agent's shifts are loaded, and keyed by the Agent: the draft is this form's state. */
function ShiftForm({ agentId, shifts }: { agentId: string; shifts: AgentShift[] }) {
  const { tenantId, client } = useDirectory()
  const server = useServerErrors()
  const [saved, setSaved] = useState(false)
  const form = useForm({
    defaultValues: { shifts: toRows(shifts) } satisfies ShiftsFormValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: shiftsFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      setSaved(false)
      try {
        await replaceAgentShifts(agentId, toInput(value))
        await client.invalidateQueries({ queryKey: queryKeys.agents.shifts(tenantId, agentId) })
        setSaved(true)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={copy.settings.shifts}
      className="grid gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      <form.Field name="shifts" mode="array">
        {(rows) => {
          const add = (weekday: number | null) =>
            rows.pushValue({
              key: crypto.randomUUID(),
              weekday,
              date: weekday === null ? '' : null,
              starts_at: '09:00',
              ends_at: '17:00',
              is_off: false,
            })
          return (
            <>
              <section
                aria-label={copy.settings.weeklyTemplate}
                className="grid gap-2 sm:grid-cols-2 lg:grid-cols-7"
              >
                {copy.settings.weekdays.map((day, weekday) => (
                  <div key={day} className="space-y-2 rounded-lg border border-border p-2">
                    <h3 className="text-sm font-semibold">{day}</h3>
                    {rows.state.value
                      .filter((entry) => entry.weekday === weekday)
                      .map((entry) => (
                        <p key={entry.key} className="text-xs">
                          {shiftLabel(entry)}
                        </p>
                      ))}
                    <Button
                      variant="outline"
                      size="sm"
                      type="button"
                      aria-label={fill(copy.settings.addForDay, { day })}
                      onClick={() => add(weekday)}
                    >
                      +
                    </Button>
                  </div>
                ))}
              </section>
              <div className="flex items-center gap-3">
                <h3 className="text-sm font-semibold">{copy.settings.dateExceptions}</h3>
                <Button type="button" variant="outline" onClick={() => add(null)}>
                  {copy.settings.addException}
                </Button>
              </div>
              <h3 className="text-sm font-semibold">{copy.settings.shiftDraft}</h3>
              {mergeMessages(rows.state.meta.errors, server.exact.shifts).map((message) => (
                <p key={message} role="alert" className="text-sm text-destructive">
                  {message}
                </p>
              ))}
              <div className="grid max-w-xl gap-3">
                {rows.state.value.map((entry, index) => (
                  <fieldset
                    key={entry.key}
                    className="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-2"
                  >
                    <legend className="sr-only">{fill(copy.settings.shiftRow, { number: index + 1 })}</legend>
                    <form.Field name={`shifts[${index}].weekday`}>
                      {(field) => (
                        <SelectField
                          id={`shift-day-${entry.key}`}
                          label={copy.settings.day}
                          value={field.state.value ?? NO_WEEKDAY}
                          options={DAY_OPTIONS}
                          onValueChange={(value) => {
                            const weekday = value === NO_WEEKDAY ? null : value
                            field.handleChange(weekday)
                            form.setFieldValue(`shifts[${index}].date`, weekday === null ? '' : null)
                          }}
                          errors={mergeMessages(
                            field.state.meta.errors,
                            server.exact[`shifts.${index}.weekday`],
                          )}
                        />
                      )}
                    </form.Field>
                    <form.Field name={`shifts[${index}].date`}>
                      {(field) => (
                        <TextField
                          id={`shift-date-${entry.key}`}
                          label={copy.settings.date}
                          type="date"
                          disabled={entry.weekday !== null}
                          value={field.state.value ?? ''}
                          onValueChange={field.handleChange}
                          onBlur={field.handleBlur}
                          errors={mergeMessages(
                            field.state.meta.errors,
                            server.exact[`shifts.${index}.date`],
                          )}
                        />
                      )}
                    </form.Field>
                    <form.Field name={`shifts[${index}].starts_at`}>
                      {(field) => (
                        <TextField
                          id={`shift-start-${entry.key}`}
                          label={copy.settings.startsAt}
                          type="time"
                          value={field.state.value}
                          onValueChange={field.handleChange}
                          onBlur={field.handleBlur}
                          errors={mergeMessages(
                            field.state.meta.errors,
                            server.exact[`shifts.${index}.starts_at`],
                          )}
                        />
                      )}
                    </form.Field>
                    <form.Field name={`shifts[${index}].ends_at`}>
                      {(field) => (
                        <TextField
                          id={`shift-end-${entry.key}`}
                          label={copy.settings.endsAt}
                          type="time"
                          value={field.state.value}
                          onValueChange={field.handleChange}
                          onBlur={field.handleBlur}
                          errors={mergeMessages(
                            field.state.meta.errors,
                            server.exact[`shifts.${index}.ends_at`],
                          )}
                        />
                      )}
                    </form.Field>
                    <form.Field name={`shifts[${index}].is_off`}>
                      {(field) => (
                        <FormField
                          id={`shift-off-${entry.key}`}
                          label={copy.settings.dayOff}
                          errors={mergeMessages(
                            field.state.meta.errors,
                            server.exact[`shifts.${index}.is_off`],
                          )}
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
                    <Button
                      variant="outline"
                      type="button"
                      className="self-end"
                      onClick={() => rows.removeValue(index)}
                    >
                      {copy.settings.removeShift}
                    </Button>
                  </fieldset>
                ))}
              </div>
            </>
          )
        }}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <div>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? copy.settings.saving : copy.settings.saveShifts}
            </Button>
          </div>
        )}
      </form.Subscribe>
      {server.failure ? <FormErrorBanner title={copy.settings.failed} error={server.failure} /> : null}
      <Saved show={saved} />
    </form>
  )
}

/** Settings → Shifts: the schedule editor, then the workspace's `shifts.enforce` switch (settings.manage). */
export function ShiftSettings() {
  const allowed = useCan('agents.view')
  if (!allowed) return <ForbiddenState />
  return (
    <div className="space-y-8">
      <ShiftSchedule />
      <ShiftEnforcementCard />
    </div>
  )
}

function ShiftSchedule() {
  const allowed = useCan('agents.view')
  const canManage = useCan('shifts.manage')
  const { session } = useSession()
  const { tenantId } = useDirectory()
  const agents = useQuery({
    ...agentQueries.list(tenantId, { ...PICKER_PAGE, sort: 'created_at' }),
    enabled: canManage && tenantId !== '',
  })
  const [agentId, setAgentId] = useState(session?.agent_profile?.id ?? '')
  const shifts = useQuery({ ...agentQueries.shifts(tenantId, agentId), enabled: allowed && agentId !== '' })
  if (!allowed) return <ForbiddenState />
  return (
    <Section title={copy.settings.shifts}>
      <p className="text-sm text-muted-foreground">
        {fill(copy.settings.shiftZone, { zone: session?.tenant.timezone ?? 'UTC' })}
      </p>
      {canManage ? (
        <div className="max-w-sm">
          <SelectField
            id="shift-agent"
            label={copy.settings.selectAgent}
            placeholder={copy.settings.select}
            value={agentId === '' ? null : agentId}
            onValueChange={setAgentId}
            options={agents.data?.data.map((agent) => ({ value: agent.id, label: agent.user.name })) ?? []}
          />
        </div>
      ) : null}
      {agentId === '' ? null : shifts.isPending ? (
        <p aria-busy="true">{copy.settings.loading}</p>
      ) : shifts.isError ? (
        <ErrorState error={shifts.error} onRetry={() => void shifts.refetch()} />
      ) : canManage ? (
        <ShiftForm key={agentId} agentId={agentId} shifts={shifts.data} />
      ) : (
        <Rows
          rows={shifts.data}
          render={(shift) => (
            <span>
              {fill(copy.settings.shiftSummary, {
                day: shift.date ?? copy.settings.weekdays[shift.weekday ?? 0] ?? '',
                shift: shiftLabel(shift),
              })}
            </span>
          )}
        />
      )}
    </Section>
  )
}

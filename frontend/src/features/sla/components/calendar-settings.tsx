import { useQuery, useQueryClient } from '@tanstack/react-query'
import { format } from 'date-fns'
import { useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { SettingsPage } from '@/components/shared/settings-page'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { deleteHoliday, slaQueries } from '../api/sla-queries'
import { CalendarForm, type EditableCalendar } from './calendar-form'
import { HolidayForm } from './holiday-form'
import { WeekSchedule } from './sla-readable'

interface HolidayRemoval {
  calendarId: string
  holidayId: string
  name: string
  date: string
}

/** A holiday's calendar date in words: "20 Oct 2026", or "20 Oct" when it repeats every year. */
function calendarDate(value: string, yearly: boolean): string {
  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1)
  return format(date, yearly ? 'd MMM' : 'd MMM yyyy')
}

/**
 * Settings → Business calendars (roadmap M4-12): each calendar as its working week (windows in words
 * with a 24-hour bar per day and the weekly total) and its holidays in readable dates.
 */
export function CalendarSettings() {
  const allowed = useCan('tickets.view')
  const canManage = useCan('calendars.manage')
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const calendars = useQuery({ ...slaQueries.calendars(tenantId), enabled: allowed && tenantId !== '' })
  const [editing, setEditing] = useState<EditableCalendar | null | undefined>(undefined)
  const [holidayCalendar, setHolidayCalendar] = useState<string | null>(null)
  const [removal, setRemoval] = useState<HolidayRemoval | null>(null)

  if (!allowed) return <ForbiddenState />
  return (
    <SettingsPage
      title={copy.sla.calendars}
      description={copy.sla.readable.calendarsDescription}
      actions={canManage ? <Button onClick={() => setEditing(null)}>{copy.sla.addCalendar}</Button> : null}
    >
      {editing !== undefined ? (
        <CalendarForm key={editing?.id ?? 'new'} calendar={editing} onDone={() => setEditing(undefined)} />
      ) : null}
      {calendars.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : calendars.isError ? (
        <ErrorState error={calendars.error} onRetry={() => void calendars.refetch()} />
      ) : calendars.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{copy.sla.noCalendars}</p>
      ) : (
        <ul className="flex flex-col gap-4">
          {calendars.data.map((calendar) => (
            <li key={calendar.id} className="space-y-3 rounded-lg border border-border p-4">
              <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">
                  {fill(copy.sla.calendarLine, { name: calendar.name, zone: calendar.timezone })}
                </h3>
                {canManage ? (
                  <Button
                    variant="outline"
                    aria-label={fill(copy.sla.editNamed, { name: calendar.name })}
                    onClick={() => setEditing(calendar)}
                  >
                    {copy.sla.edit}
                  </Button>
                ) : null}
              </div>
              {/* The working week in words and at a glance (M4-12). */}
              <WeekSchedule name={calendar.name} hours={calendar.weekly_hours} />
              <div className="flex items-center justify-between gap-2">
                <h4 className="text-sm font-medium">{copy.sla.holidays}</h4>
                {canManage ? (
                  <Button
                    type="button"
                    variant="outline"
                    aria-label={fill(copy.sla.addHolidayTo, { name: calendar.name })}
                    onClick={() => setHolidayCalendar(calendar.id)}
                  >
                    {copy.sla.addHoliday}
                  </Button>
                ) : null}
              </div>
              {calendar.holidays.length > 0 ? (
                <ul className="space-y-1">
                  {calendar.holidays.map((item) => (
                    <li key={item.id} className="flex items-center justify-between gap-2 text-sm">
                      <span>
                        {fill(item.recurs_yearly ? copy.sla.holidayLineAnnual : copy.sla.holidayLine, {
                          date: calendarDate(item.date, item.recurs_yearly),
                          name: item.name,
                        })}
                      </span>
                      {canManage ? (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          aria-label={fill(copy.sla.removeHolidayNamed, { name: item.name })}
                          onClick={() =>
                            setRemoval({
                              calendarId: calendar.id,
                              holidayId: item.id,
                              name: item.name,
                              date: item.date,
                            })
                          }
                        >
                          {copy.sla.remove}
                        </Button>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">{copy.sla.noHolidays}</p>
              )}
              {holidayCalendar === calendar.id ? (
                <HolidayForm calendar={calendar} onDone={() => setHolidayCalendar(null)} />
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <ConfirmDialog
        open={removal !== null}
        onOpenChange={(open) => {
          if (!open) setRemoval(null)
        }}
        destructive
        title={copy.sla.removeHolidayTitle}
        description={
          removal
            ? fill(copy.sla.removeHolidayBody, {
                name: removal.name,
                date: calendarDate(removal.date, false),
              })
            : ''
        }
        confirmLabel={copy.sla.remove}
        failedTitle={copy.sla.holidayRemoveFailed}
        onConfirm={async () => {
          if (!removal) return
          await deleteHoliday(removal.calendarId, removal.holidayId)
          await client.invalidateQueries({ queryKey: queryKeys.sla.all(tenantId) })
          toast.success(copy.sla.holidayRemoved)
        }}
      />
    </SettingsPage>
  )
}

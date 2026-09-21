import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { deleteHoliday, slaQueries } from '../api/sla-queries'
import { CalendarForm, type EditableCalendar } from './calendar-form'
import { HolidayForm } from './holiday-form'

interface HolidayRemoval {
  calendarId: string
  holidayId: string
  name: string
  date: string
}

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
    <section className="space-y-4" aria-labelledby="calendars-heading">
      <h2 id="calendars-heading" className="text-lg font-semibold">
        {copy.sla.calendars}
      </h2>
      {canManage ? <Button onClick={() => setEditing(null)}>{copy.sla.addCalendar}</Button> : null}
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
        <ul className="divide-y divide-border rounded-lg border border-border">
          {calendars.data.map((calendar) => (
            <li key={calendar.id} className="space-y-3 p-3">
              <div className="flex items-center justify-between gap-2">
                <span>{fill(copy.sla.calendarLine, { name: calendar.name, zone: calendar.timezone })}</span>
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
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium">{copy.sla.holidays}</h3>
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
                          date: item.date,
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
          removal ? fill(copy.sla.removeHolidayBody, { name: removal.name, date: removal.date }) : ''
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
    </section>
  )
}

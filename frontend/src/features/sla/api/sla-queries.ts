import { queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type SlaTimer = components['schemas']['TicketSlaTimerResource']
export type SlaPolicy = components['schemas']['SlaPolicyResource']
export type SlaPolicyInput = components['schemas']['SavePolicyRequest']
export type Calendar = components['schemas']['BusinessCalendarResource']
export type CalendarInput = components['schemas']['SaveCalendarRequest']
export type HolidayInput = components['schemas']['SaveHolidayRequest']

export const slaQueries = {
  ticket: (tenantId: string, ticketId: string) =>
    queryOptions({
      queryKey: queryKeys.sla.ticket(tenantId, ticketId),
      queryFn: () => unwrap(api().GET('/tickets/{ticket}/sla', { params: { path: { ticket: ticketId } } })),
      refetchInterval: 30_000,
    }),
  policies: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.sla.policies(tenantId),
      queryFn: () => unwrap(api().GET('/sla-policies')),
    }),
  calendars: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.sla.calendars(tenantId),
      queryFn: () => unwrap(api().GET('/calendars')),
    }),
}

export const createPolicy = (input: SlaPolicyInput) => unwrap(api().POST('/sla-policies', { body: input }))
export const updatePolicy = (id: string, input: SlaPolicyInput) =>
  unwrap(api().PATCH('/sla-policies/{policy}', { params: { path: { policy: id } }, body: input }))
export const deletePolicy = (id: string) =>
  unwrapBody(api().DELETE('/sla-policies/{policy}', { params: { path: { policy: id } } }))

export const createCalendar = (input: CalendarInput) => unwrap(api().POST('/calendars', { body: input }))
export const updateCalendar = (id: string, input: CalendarInput) =>
  unwrap(api().PATCH('/calendars/{calendar}', { params: { path: { calendar: id } }, body: input }))
export const addHoliday = (id: string, input: HolidayInput) =>
  unwrap(api().POST('/calendars/{calendar}/holidays', { params: { path: { calendar: id } }, body: input }))
export const deleteHoliday = (id: string, holidayId: string) =>
  unwrapBody(
    api().DELETE('/calendars/{calendar}/holidays/{holiday}', {
      params: { path: { calendar: id, holiday: holidayId } },
    }),
  )

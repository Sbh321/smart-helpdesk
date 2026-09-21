import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { db, nextId } from './data'
import { apiUrl, problem } from './handlers'
import { validationFailed } from './list'

type PolicyInput = components['schemas']['SavePolicyRequest']
type CalendarInput = components['schemas']['SaveCalendarRequest']
type HolidayInput = components['schemas']['SaveHolidayRequest']

const notFound = () => problem(404, 'not_found', { title: 'Not found' })
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/

function policyErrors(body: Partial<PolicyInput>, ignoreId?: string): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if (!body.name?.trim()) errors.name = ['The name field is required.']
  else if (db.slaPolicies.some((row) => row.id !== ignoreId && row.name === body.name?.trim()))
    errors.name = ['The name has already been taken.']
  if (
    typeof body.warning_fraction !== 'number' ||
    body.warning_fraction < 0.1 ||
    body.warning_fraction > 0.95
  )
    errors.warning_fraction = ['The warning fraction field must be between 0.1 and 0.95.']
  for (const [index, target] of (body.targets ?? []).entries()) {
    if (target.resolution_minutes < target.first_response_minutes)
      errors[`targets.${index}.resolution_minutes`] = [
        'The resolution target must not be shorter than the first response target.',
      ]
  }
  return errors
}

/** `Intl.supportedValuesOf` lists canonical names only (Chromium says `Asia/Katmandu`), so ask the formatter. */
function isTimeZone(value: string): boolean {
  try {
    new Intl.DateTimeFormat('en', { timeZone: value })
    return true
  } catch {
    return false
  }
}

function calendarErrors(body: Partial<CalendarInput>): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if (!body.name?.trim()) errors.name = ['The name field is required.']
  if (!body.timezone || !isTimeZone(body.timezone))
    errors.timezone = ['The timezone field must be a valid timezone.']
  for (const [day, windows] of Object.entries(body.weekly_hours ?? {})) {
    for (const [index, pair] of (windows as [string, string][]).entries()) {
      if (!TIME.test(pair[0]) || !TIME.test(pair[1]) || pair[0] >= pair[1])
        errors[`weekly_hours.${day}.${index}`] = ['The window must end after it starts.']
    }
  }
  return errors
}

export const slaHandlers = [
  http.get(apiUrl('/tickets/{ticket}/sla'), ({ params }) =>
    HttpResponse.json({ data: db.slaTimers[String(params.ticket)] ?? [] }),
  ),
  http.get(apiUrl('/sla-policies'), () => HttpResponse.json({ data: db.slaPolicies })),
  http.post(apiUrl('/sla-policies'), async ({ request }) => {
    const body = (await request.json()) as PolicyInput
    const errors = policyErrors(body)
    if (Object.keys(errors).length) return validationFailed(errors)
    const policy = {
      id: nextId(12),
      name: body.name.trim(),
      is_default: body.is_default ?? false,
      applies_to_tier: body.applies_to_tier ?? null,
      warning_fraction: body.warning_fraction,
      calendar_id: body.calendar_id ?? null,
      version: 1,
      targets: body.targets,
    }
    db.slaPolicies.push(policy)
    return HttpResponse.json({ data: policy }, { status: 201 })
  }),
  http.patch(apiUrl('/sla-policies/{policy}'), async ({ params, request }) => {
    const policy = db.slaPolicies.find((row) => row.id === params.policy)
    if (!policy) return notFound()
    const body = (await request.json()) as PolicyInput
    const errors = policyErrors(body, policy.id)
    if (Object.keys(errors).length) return validationFailed(errors)
    Object.assign(policy, body, { name: body.name.trim(), version: policy.version + 1 })
    return HttpResponse.json({ data: policy })
  }),
  http.delete(apiUrl('/sla-policies/{policy}'), ({ params }) => {
    const policy = db.slaPolicies.find((row) => row.id === params.policy)
    if (!policy) return notFound()
    if (policy.is_default)
      return problem(409, 'in_use', { detail: 'The default SLA policy cannot be deleted.' })
    db.slaPolicies = db.slaPolicies.filter((row) => row.id !== policy.id)
    return new HttpResponse(null, { status: 204 })
  }),
  http.get(apiUrl('/calendars'), () => HttpResponse.json({ data: db.calendars })),
  http.post(apiUrl('/calendars'), async ({ request }) => {
    const body = (await request.json()) as CalendarInput
    const errors = calendarErrors(body)
    if (Object.keys(errors).length) return validationFailed(errors)
    const calendar = {
      id: nextId(10),
      name: body.name.trim(),
      timezone: body.timezone,
      weekly_hours: body.weekly_hours as Record<string, [string, string][]>,
      is_default: body.is_default ?? false,
      holidays: [],
    }
    db.calendars.push(calendar)
    return HttpResponse.json({ data: calendar }, { status: 201 })
  }),
  http.patch(apiUrl('/calendars/{calendar}'), async ({ params, request }) => {
    const calendar = db.calendars.find((row) => row.id === params.calendar)
    if (!calendar) return notFound()
    const body = (await request.json()) as CalendarInput
    const errors = calendarErrors(body)
    if (Object.keys(errors).length) return validationFailed(errors)
    Object.assign(calendar, body, { name: body.name.trim() })
    return HttpResponse.json({ data: calendar })
  }),
  http.post(apiUrl('/calendars/{calendar}/holidays'), async ({ params, request }) => {
    const calendar = db.calendars.find((row) => row.id === params.calendar)
    if (!calendar) return notFound()
    const body = (await request.json()) as HolidayInput
    const errors: Record<string, string[]> = {}
    if (!body.name?.trim()) errors.name = ['The name field is required.']
    if (!/^\d{4}-\d{2}-\d{2}$/.test(body.date ?? '')) errors.date = ['The date field must be a valid date.']
    else if (calendar.holidays.some((row) => row.date === body.date))
      errors.date = ['The date has already been taken.']
    if (Object.keys(errors).length) return validationFailed(errors)
    const holiday = {
      id: nextId(11),
      date: body.date,
      name: body.name.trim(),
      recurs_yearly: body.recurs_yearly ?? false,
    }
    calendar.holidays.push(holiday)
    return HttpResponse.json({ data: calendar })
  }),
  http.delete(apiUrl('/calendars/{calendar}/holidays/{holiday}'), ({ params }) => {
    const calendar = db.calendars.find((row) => row.id === params.calendar)
    if (!calendar) return notFound()
    calendar.holidays = calendar.holidays.filter((row) => row.id !== params.holiday)
    return new HttpResponse(null, { status: 204 })
  }),
]

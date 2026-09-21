import { z } from 'zod'
import { copy } from '@/copy/en'
import { isTimeZone } from '@/lib/datetime/time-zones'
import { decimalText, integerText } from '@/lib/forms/numbers'
import { DAYS } from './weekly-hours'

const rules = copy.sla.validation

export const SLA_PRIORITIES = ['P1', 'P2', 'P3', 'P4'] as const
export const SLA_TIERS = ['standard', 'premium', 'enterprise'] as const
/** `Select` needs a value for "no tier" and "no calendar"; `toInput()` turns both into `null`. */
export const ALL_TIERS = 'all'
export const ALWAYS_OPEN = '24x7'
const MINUTES_IN_A_YEAR = 525_600

const minutes = integerText(1, MINUTES_IN_A_YEAR, rules.minutes)

/** Mirrors `SavePolicyRequest`; unique name and one policy per tier stay with the API (422). */
export const policyFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(80, rules.nameTooLong),
  tier: z.enum([ALL_TIERS, ...SLA_TIERS]),
  calendar: z.string().min(1),
  warning_fraction: decimalText(0.1, 0.95, rules.warningFraction),
  targets: z
    .array(
      z
        .object({
          priority_level: z.enum(SLA_PRIORITIES),
          first_response_minutes: minutes,
          resolution_minutes: minutes,
        })
        .refine((target) => Number(target.resolution_minutes) >= Number(target.first_response_minutes), {
          message: rules.resolutionBeforeResponse,
          path: ['resolution_minutes'],
        }),
    )
    .length(SLA_PRIORITIES.length),
})
export type PolicyFormValues = z.input<typeof policyFormSchema>

const time = z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, rules.time)

const windowRow = z
  .object({ id: z.string(), start: time, end: time })
  .refine((row) => row.start < row.end, { message: rules.windowOrder, path: ['end'] })

/** Windows of one day may not overlap; the message lands on the later window's start. */
const dayWindows = z.array(windowRow).superRefine((rows, context) => {
  rows.forEach((row, index) => {
    const clash = rows.some(
      (other, otherIndex) => otherIndex < index && row.start < other.end && other.start < row.end,
    )
    if (clash) context.addIssue({ code: 'custom', message: rules.windowOverlap, path: [index, 'start'] })
  })
})

export { isTimeZone }

/** Mirrors `SaveCalendarRequest`: an IANA zone and at least one valid weekly window. */
export const calendarFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(80, rules.nameTooLong),
  timezone: z.string().trim().min(1, rules.timeZone).refine(isTimeZone, rules.timeZone),
  windows: z
    .object(
      Object.fromEntries(DAYS.map((day) => [day, dayWindows])) as Record<
        (typeof DAYS)[number],
        typeof dayWindows
      >,
    )
    .refine((week) => DAYS.some((day) => week[day].length > 0), rules.windowRequired),
})
export type CalendarFormValues = z.input<typeof calendarFormSchema>

/** Mirrors `SaveHolidayRequest`; one holiday per date stays with the API (422). */
export const holidayFormSchema = z.object({
  date: z.iso.date(rules.date),
  name: z.string().trim().min(1, rules.nameRequired).max(120, rules.holidayNameTooLong),
  recurs_yearly: z.boolean(),
})
export type HolidayFormValues = z.input<typeof holidayFormSchema>

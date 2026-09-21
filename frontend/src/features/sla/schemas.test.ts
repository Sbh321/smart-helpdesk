import { describe, expect, it } from 'vitest'
import { copy } from '@/copy/en'
import { calendarFormSchema, holidayFormSchema, isTimeZone, policyFormSchema } from './schemas'
import { emptyWeek } from './weekly-hours'

const rules = copy.sla.validation

function messages(result: {
  success: boolean
  error?: { issues: { message: string; path: PropertyKey[] }[] }
}) {
  return (result.error?.issues ?? []).map((issue) => `${issue.path.join('.')}: ${issue.message}`)
}

const policy = {
  name: 'Standard',
  tier: 'all',
  calendar: '24x7',
  warning_fraction: '0.75',
  targets: (['P1', 'P2', 'P3', 'P4'] as const).map((priority_level) => ({
    priority_level,
    first_response_minutes: '60',
    resolution_minutes: '480',
  })),
}

describe('policyFormSchema', () => {
  it('accepts the defaults', () => {
    expect(policyFormSchema.safeParse(policy).success).toBe(true)
  })

  it('names the target row whose resolution is shorter than its first response', () => {
    const targets = policy.targets.map((row, index) =>
      index === 1 ? { ...row, resolution_minutes: '30' } : row,
    )
    expect(messages(policyFormSchema.safeParse({ ...policy, targets }))).toEqual([
      `targets.1.resolution_minutes: ${rules.resolutionBeforeResponse}`,
    ])
  })

  it('rejects an out-of-range warning fraction and empty minutes', () => {
    const targets = policy.targets.map((row, index) =>
      index === 0 ? { ...row, first_response_minutes: '' } : row,
    )
    const found = messages(policyFormSchema.safeParse({ ...policy, warning_fraction: '1', targets }))
    expect(found).toContain(`warning_fraction: ${rules.warningFraction}`)
    expect(found).toContain(`targets.0.first_response_minutes: ${rules.minutes}`)
  })
})

describe('calendarFormSchema', () => {
  const window = (id: string, start: string, end: string) => ({ id, start, end })

  it('accepts touching windows and rejects overlapping or reversed ones', () => {
    const base = { name: 'Office', timezone: 'Asia/Kathmandu' }
    expect(
      calendarFormSchema.safeParse({
        ...base,
        windows: { ...emptyWeek(), mon: [window('a', '09:00', '12:00'), window('b', '12:00', '17:00')] },
      }).success,
    ).toBe(true)
    expect(
      messages(
        calendarFormSchema.safeParse({
          ...base,
          windows: { ...emptyWeek(), tue: [window('a', '09:00', '13:00'), window('b', '12:00', '17:00')] },
        }),
      ),
    ).toEqual([`windows.tue.1.start: ${rules.windowOverlap}`])
    expect(
      messages(
        calendarFormSchema.safeParse({
          ...base,
          windows: { ...emptyWeek(), wed: [window('a', '17:00', '09:00')] },
        }),
      ),
    ).toEqual([`windows.wed.0.end: ${rules.windowOrder}`])
  })

  it('needs a window and an IANA zone', () => {
    const found = messages(
      calendarFormSchema.safeParse({ name: 'x', timezone: 'Mars/Olympus', windows: emptyWeek() }),
    )
    expect(found).toContain(`timezone: ${rules.timeZone}`)
    expect(found).toContain(`windows: ${rules.windowRequired}`)
    expect(isTimeZone('UTC')).toBe(true)
  })
})

describe('holidayFormSchema', () => {
  it('needs a date and a name', () => {
    expect(messages(holidayFormSchema.safeParse({ date: '', name: ' ', recurs_yearly: false }))).toEqual([
      `date: ${rules.date}`,
      `name: ${rules.nameRequired}`,
    ])
  })
})

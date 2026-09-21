import { describe, expect, it } from 'vitest'
import { copy } from '@/copy/en'
import {
  brandingFormSchema,
  duplicatesFormSchema,
  generalFormSchema,
  readSection,
  slaDefaultsFormSchema,
  ticketsFormSchema,
} from './schemas'

const rules = copy.workspaceSettings.validation

function messages(result: {
  success: boolean
  error?: { issues: { path: PropertyKey[]; message: string }[] }
}) {
  return Object.fromEntries(
    (result.error?.issues ?? []).map((issue) => [issue.path.join('.'), issue.message]),
  )
}

describe('generalFormSchema', () => {
  it('needs a name of 2 to 80 characters and an IANA time zone', () => {
    expect(generalFormSchema.safeParse({ name: 'Acme Support', timezone: 'Asia/Kathmandu' }).success).toBe(
      true,
    )
    expect(messages(generalFormSchema.safeParse({ name: ' A ', timezone: 'Mars/Olympus' }))).toEqual({
      name: rules.nameLength,
      timezone: rules.timezone,
    })
    expect(messages(generalFormSchema.safeParse({ name: 'x'.repeat(81), timezone: '' }))).toEqual({
      name: rules.nameLength,
      timezone: rules.timezone,
    })
  })
})

describe('brandingFormSchema', () => {
  const logos = { logo_media_id: null, logo_dark_media_id: null }

  it('takes an empty colour (no brand) or a readable #rrggbb', () => {
    expect(brandingFormSchema.safeParse({ primary: '', ...logos }).success).toBe(true)
    expect(brandingFormSchema.safeParse({ primary: ' #0F766E ', ...logos }).success).toBe(true)
  })

  it('rejects other formats and colours without 4.5:1 text', () => {
    expect(messages(brandingFormSchema.safeParse({ primary: 'teal', ...logos }))).toEqual({
      primary: rules.hex,
    })
    expect(messages(brandingFormSchema.safeParse({ primary: '#777777', ...logos }))).toEqual({
      primary: rules.contrast,
    })
  })
})

describe('number forms', () => {
  it('mirror the API ranges for duplicates, tickets and SLA defaults', () => {
    expect(
      duplicatesFormSchema.safeParse({
        threshold: '0.35',
        candidate_limit: '50',
        window_days: '30',
        max_suggestions: '5',
      }).success,
    ).toBe(true)
    expect(
      Object.keys(
        messages(
          duplicatesFormSchema.safeParse({
            threshold: '1.5',
            candidate_limit: '0',
            window_days: '366',
            max_suggestions: '2.5',
          }),
        ),
      ),
    ).toEqual(['threshold', 'candidate_limit', 'window_days', 'max_suggestions'])

    expect(ticketsFormSchema.safeParse({ auto_close_days: '7', reopen_window_days: '0' }).success).toBe(true)
    expect(
      Object.keys(messages(ticketsFormSchema.safeParse({ auto_close_days: '0', reopen_window_days: '-1' }))),
    ).toEqual(['auto_close_days', 'reopen_window_days'])

    expect(
      slaDefaultsFormSchema.safeParse({
        warning_fraction: '0.75',
        first_response_applies_to_agent_created: true,
      }).success,
    ).toBe(true)
    expect(
      Object.keys(
        messages(
          slaDefaultsFormSchema.safeParse({
            warning_fraction: '0.99',
            first_response_applies_to_agent_created: true,
          }),
        ),
      ),
    ).toEqual(['warning_fraction'])
  })
})

describe('readSection', () => {
  it('types values and defaults, and refuses another shape', () => {
    const section = { values: { enabled: false }, defaults: { enabled: true } }
    expect(readSection('automation.assignment', section)).toEqual(section)
    expect(() => readSection('automation.assignment', { values: { enabled: 'no' }, defaults: {} })).toThrow()
  })
})

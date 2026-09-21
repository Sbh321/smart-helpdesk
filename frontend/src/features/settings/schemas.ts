import { z } from 'zod'
import { copy } from '@/copy/en'
import { isTimeZone } from '@/lib/datetime/time-zones'
import { decimalText, integerText } from '@/lib/forms/numbers'
import { brandContrast, HEX_COLOR_PATTERN } from '@/lib/theme'

const rules = copy.workspaceSettings.validation

/**
 * `SettingsSectionResource.values` and `.defaults` are free-form objects in the OpenAPI document; these
 * schemas give each section its shape (docs/03-architecture/configuration.md).
 */
export const sectionValues = {
  general: z.object({ name: z.string(), timezone: z.string() }),
  branding: z.object({
    primary: z.string().nullable(),
    logo_media_id: z.string().nullable(),
    logo_dark_media_id: z.string().nullable(),
  }),
  'automation.priority': z.object({
    baseline: z.object({
      weights: z.object({ impact: z.number(), urgency: z.number(), tier: z.number(), age: z.number() }),
      thresholds: z.object({ P1: z.number(), P2: z.number(), P3: z.number() }),
      age_full_hours: z.number(),
    }),
  }),
  'automation.assignment': z.object({ enabled: z.boolean() }),
  'automation.duplicates': z.object({
    baseline: z.object({
      threshold: z.number(),
      candidate_limit: z.number(),
      window_days: z.number(),
      max_suggestions: z.number(),
    }),
  }),
  tickets: z.object({ auto_close_days: z.number(), reopen_window_days: z.number() }),
  sla: z.object({ warning_fraction: z.number(), first_response_applies_to_agent_created: z.boolean() }),
  shifts: z.object({ enforce: z.boolean() }),
  features: z.object({ realtime: z.boolean(), exports: z.boolean() }),
} as const

export type SectionValues<K extends keyof typeof sectionValues> = z.infer<(typeof sectionValues)[K]>

/** Typed `values` and `defaults` of a section; throws when the API sent another shape. */
export function readSection<K extends keyof typeof sectionValues>(
  key: K,
  section: { values: unknown; defaults: unknown },
): { values: SectionValues<K>; defaults: SectionValues<K> } {
  const schema = sectionValues[key]
  return {
    values: schema.parse(section.values) as SectionValues<K>,
    defaults: schema.parse(section.defaults) as SectionValues<K>,
  }
}

/** Mirrors `GeneralSection::rules()`. */
export const generalFormSchema = z.object({
  name: z.string().trim().min(2, rules.nameLength).max(80, rules.nameLength),
  timezone: z.string().trim().min(1, rules.timezone).refine(isTimeZone, rules.timezone),
})
export type GeneralFormValues = z.input<typeof generalFormSchema>

/**
 * Mirrors `BrandingSection`: an empty colour means "no brand colour" (`null`); a colour must be
 * `#rrggbb` and carry light or dark text at 4.5:1 (tokens.md §Tenant branding).
 */
export const brandingFormSchema = z.object({
  primary: z
    .string()
    .trim()
    .refine((value) => value === '' || HEX_COLOR_PATTERN.test(value), rules.hex)
    .refine((value) => brandContrast(value)?.passes ?? true, rules.contrast),
  logo_media_id: z.string().nullable(),
  logo_dark_media_id: z.string().nullable(),
})
export type BrandingFormValues = z.input<typeof brandingFormSchema>

/** Mirrors the `automation.duplicates` rules. */
export const duplicatesFormSchema = z.object({
  threshold: decimalText(0, 1, rules.threshold),
  candidate_limit: integerText(1, 200, rules.candidateLimit),
  window_days: integerText(1, 365, rules.windowDays),
  max_suggestions: integerText(1, 20, rules.maxSuggestions),
})
export type DuplicatesFormValues = z.input<typeof duplicatesFormSchema>

/** Mirrors the `tickets` rules. */
export const ticketsFormSchema = z.object({
  auto_close_days: integerText(1, 90, rules.autoCloseDays),
  reopen_window_days: integerText(0, 365, rules.reopenWindowDays),
})
export type TicketsFormValues = z.input<typeof ticketsFormSchema>

/** Mirrors the `sla` rules. */
export const slaDefaultsFormSchema = z.object({
  warning_fraction: decimalText(0.1, 0.95, rules.warningFraction),
  first_response_applies_to_agent_created: z.boolean(),
})
export type SlaDefaultsFormValues = z.input<typeof slaDefaultsFormSchema>

import { z } from 'zod'
import { copy } from '@/copy/en'
import { integerText } from '@/lib/forms/numbers'

const rules = copy.settings.validation

export const AGENT_AVAILABILITIES = ['available', 'away', 'offline'] as const

export const skillAssignmentSchema = z.object({
  skill_id: z.uuid(),
  level: z.number().int().min(1).max(5),
})

export const agentFormSchema = z.object({
  user_id: z.uuid(),
  capacity: z.number().int().min(1).max(100),
  availability: z.enum(AGENT_AVAILABILITIES),
  skills: z.array(skillAssignmentSchema).max(50),
  team_ids: z.array(z.uuid()).max(50),
})

const time = z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, rules.time)

export const agentShiftSchema = z
  .object({
    weekday: z.number().int().min(0).max(6).nullable(),
    date: z.iso.date(rules.shiftDate).nullable(),
    starts_at: time,
    ends_at: time,
    is_off: z.boolean(),
  })
  .refine((shift) => (shift.weekday === null) !== (shift.date === null), {
    message: rules.shiftDay,
    path: ['weekday'],
  })
  .refine((shift) => shift.starts_at < shift.ends_at, {
    message: rules.shiftOrder,
    path: ['ends_at'],
  })

export type AgentFormValues = z.input<typeof agentFormSchema>
export type AgentShiftValues = z.input<typeof agentShiftSchema>

/**
 * The settings forms' values are the UI's shape: picked records are `{ value, label }`, numbers are the
 * input's text. Each form converts them to the API request in its own `toInput()`.
 */
const option = z.object({ value: z.string(), label: z.string(), detail: z.string().optional() })

export const SKILL_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/** Mirrors `SaveSkillRequest`; uniqueness of name and slug stays with the API (422). */
export const skillFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(60, rules.nameTooLong60),
  slug: z
    .string()
    .trim()
    .min(1, rules.slugRequired)
    .max(60, rules.slugInvalid)
    .regex(SKILL_SLUG, rules.slugInvalid),
  description: z.string().trim().max(1000, rules.descriptionTooLong),
})
export type SkillFormValues = z.input<typeof skillFormSchema>

/** Mirrors `SaveTeamRequest` plus the members sent to `PUT /teams/{team}/members`. */
export const teamFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(80, rules.nameTooLong80),
  description: z.string().trim().max(1000, rules.descriptionTooLong),
  members: z.array(option),
})
export type TeamFormValues = z.input<typeof teamFormSchema>

/** Mirrors `SaveCategoryRequest`. */
export const categoryFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(80, rules.nameTooLong80),
  team: option.nullable(),
  skills: z.array(option).max(50, rules.tooMany),
})
export type CategoryFormValues = z.input<typeof categoryFormSchema>

/** Mirrors `SaveAgentRequest`; `user` is required only when creating (the edit form does not show it). */
export const agentSettingsFormSchema = z.object({
  user: option.nullable(),
  capacity: integerText(1, 100, rules.capacity),
  availability: z.enum(AGENT_AVAILABILITIES),
  skills: z.array(option.extend({ level: integerText(1, 5, rules.skillLevel) })).max(50, rules.tooMany),
  teams: z.array(option).max(50, rules.tooMany),
})
export const newAgentSettingsFormSchema = agentSettingsFormSchema.refine((values) => values.user !== null, {
  message: rules.userRequired,
  path: ['user'],
})
export type AgentSettingsFormValues = z.input<typeof agentSettingsFormSchema>

/** The whole schedule is replaced at once (`PUT /agents/{agent}/shifts`); `key` is the row's React key. */
export const shiftsFormSchema = z.object({
  shifts: z.array(agentShiftSchema.and(z.object({ key: z.string() }))),
})
export type ShiftsFormValues = z.input<typeof shiftsFormSchema>

import { z } from 'zod'
import { copy } from '@/copy/en'
import { decimalText } from '@/lib/forms/numbers'

const rules = copy.priority.validation

export const PRIORITY_WEIGHTS = ['impact', 'urgency', 'tier', 'age'] as const
export const PRIORITY_THRESHOLDS = ['P1', 'P2', 'P3'] as const

const weight = decimalText(0, 1, rules.weight)
const threshold = decimalText(0, 100, rules.threshold)

/**
 * Mirrors `PreviewPriorityRequest` plus the rules of docs/05-algorithms/priority-scoring.md the API also
 * checks: the weights add up to 1 and the thresholds fall from P1 to P3.
 */
export const prioritySettingsFormSchema = z
  .object({
    weights: z.object({ impact: weight, urgency: weight, tier: weight, age: weight }),
    thresholds: z.object({ P1: threshold, P2: threshold, P3: threshold }),
    age_full_hours: z
      .string()
      .trim()
      .refine((value) => /^\d+(\.\d+)?$/.test(value) && Number(value) > 0, rules.ageFullHours),
  })
  .superRefine((values, context) => {
    const weights = PRIORITY_WEIGHTS.map((name) => Number(values.weights[name]))
    if (
      weights.every(Number.isFinite) &&
      Math.abs(weights.reduce((sum, value) => sum + value, 0) - 1) > 0.001
    ) {
      context.addIssue({ code: 'custom', message: rules.weightSum, path: ['weights'] })
    }
    const { P1, P2, P3 } = values.thresholds
    if (Number(P2) >= Number(P1)) {
      context.addIssue({ code: 'custom', message: rules.thresholdOrderP2, path: ['thresholds', 'P2'] })
    }
    if (Number(P3) >= Number(P2)) {
      context.addIssue({ code: 'custom', message: rules.thresholdOrderP3, path: ['thresholds', 'P3'] })
    }
  })
export type PrioritySettingsFormValues = z.input<typeof prioritySettingsFormSchema>

import { z } from 'zod'
import { copy } from '@/copy/en'

const rules = copy.tickets.validation
const level = z
  .number({ error: rules.levelRequired })
  .int()
  .min(1, rules.levelRequired)
  .max(4, rules.levelRequired)
  .nullable()
  .refine((value) => value !== null, rules.levelRequired)

/** Mirrors `StoreTicketRequest` (docs/04-domain/tickets.md); the API stays the authority. */
export const ticketFormSchema = z.object({
  title: z.string().trim().min(1, rules.titleRequired).max(200, rules.titleTooLong),
  description: z.string().trim().min(1, rules.descriptionRequired),
  contact: z
    .object({ value: z.string(), label: z.string(), detail: z.string().optional() })
    .nullable()
    .refine((value) => value !== null, rules.contactRequired),
  category_id: z
    .string()
    .nullable()
    .refine((value) => value !== null && value !== '', rules.categoryRequired),
  impact: level,
  urgency: level,
  tags: z.array(z.string()),
})
export type TicketFormValues = z.input<typeof ticketFormSchema>

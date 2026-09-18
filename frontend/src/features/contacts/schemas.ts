import { z } from 'zod'
import { copy } from '@/copy/en'

const contactRules = copy.contacts.validation
const organizationRules = copy.organizations.validation

const option = z.object({ value: z.string(), label: z.string(), detail: z.string().optional() })

/** Mirrors `ContactRequest` (docs/04-domain/contacts.md); the API stays the authority (unique email → 422). */
export const contactFormSchema = z.object({
  name: z.string().trim().min(1, contactRules.nameRequired).max(150, contactRules.nameTooLong),
  email: z
    .string()
    .trim()
    .min(1, contactRules.emailRequired)
    .max(254, contactRules.emailInvalid)
    .pipe(z.email(contactRules.emailInvalid)),
  phone: z.string().trim().max(40, contactRules.phoneTooLong),
  organization: option.nullable(),
  tags: z.array(z.string()),
})
export type ContactFormValues = z.input<typeof contactFormSchema>

/** Mirrors `OrganizationRequest`: a bare domain, no scheme or path. */
export const organizationFormSchema = z.object({
  name: z.string().trim().min(1, organizationRules.nameRequired).max(150, organizationRules.nameTooLong),
  domain: z
    .string()
    .trim()
    .max(253, organizationRules.domainInvalid)
    .regex(
      /^$|^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i,
      organizationRules.domainInvalid,
    ),
  tier: z.enum(['standard', 'premium', 'enterprise']),
  tags: z.array(z.string()),
})
export type OrganizationFormValues = z.input<typeof organizationFormSchema>

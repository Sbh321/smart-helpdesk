import { z } from 'zod'
import { copy } from '@/copy/en'

const rules = copy.apiClients.validation

/** Mirrors `StoreApiClientRequest`; unknown scopes are refused by the API (422 on `scopes.N`). */
export const apiClientFormSchema = z.object({
  name: z.string().trim().min(1, rules.name).max(120, rules.name),
  scopes: z.array(z.string()).min(1, rules.scopes),
})
export type ApiClientFormValues = z.input<typeof apiClientFormSchema>

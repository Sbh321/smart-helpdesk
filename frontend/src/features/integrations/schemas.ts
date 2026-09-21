import { z } from 'zod'
import { copy } from '@/copy/en'

const rules = copy.apiClients.validation

/** Mirrors `StoreApiClientRequest`; unknown scopes are refused by the API (422 on `scopes.N`). */
export const apiClientFormSchema = z.object({
  name: z.string().trim().min(1, rules.name).max(120, rules.name),
  scopes: z.array(z.string()).min(1, rules.scopes),
})
export type ApiClientFormValues = z.input<typeof apiClientFormSchema>

const webhookRules = copy.webhooks.validation

function isHttpUrl(value: string): boolean {
  try {
    const url = new URL(value)
    return url.protocol === 'https:' || url.protocol === 'http:'
  } catch {
    return false
  }
}

/**
 * Mirrors `StoreWebhookRequest`. The API's SSRF guard has the last word (422 `webhook_url_rejected` on
 * `url`); `http:` passes here only because development receivers such as webhook-echo use it.
 */
export const webhookFormSchema = z.object({
  name: z.string().trim().min(1, webhookRules.name).max(80, webhookRules.name),
  url: z.string().trim().max(2048, webhookRules.url).refine(isHttpUrl, webhookRules.url),
  events: z.array(z.string()).min(1, webhookRules.events),
})
export type WebhookFormValues = z.input<typeof webhookFormSchema>

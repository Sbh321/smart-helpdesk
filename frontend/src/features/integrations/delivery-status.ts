import { copy, fill } from '@/copy/en'
import type { WebhookDelivery } from './api/webhook-queries'

/**
 * What a delivery is doing, in the integrator's words (roadmap M4-11). The API stores four states;
 * `failed` covers both "will be retried at `next_attempt_at`" and "no retry left", which an integrator
 * needs told apart (docs/07-api/webhooks.md §Delivery: five automatic retries, then `dead`).
 */
export type DeliveryStatus = 'queued' | 'delivered' | 'retrying' | 'failed' | 'gave_up'

export function deliveryStatus(delivery: Pick<WebhookDelivery, 'state' | 'next_attempt_at'>): DeliveryStatus {
  switch (delivery.state) {
    case 'succeeded':
      return 'delivered'
    case 'pending':
      return 'queued'
    case 'dead':
      return 'gave_up'
    default:
      return delivery.next_attempt_at ? 'retrying' : 'failed'
  }
}

/** Manual retry is offered where the API accepts it: a failed or dead delivery of an enabled webhook. */
export function canRetryDelivery(delivery: Pick<WebhookDelivery, 'state'>, webhookActive: boolean): boolean {
  return webhookActive && (delivery.state === 'failed' || delivery.state === 'dead')
}

/**
 * The stored error code as a sentence (webhooks.md §Delivery lists the codes). `url_rejected: <reason>`
 * keeps its reason; an unknown code is shown as sent, so nothing is hidden.
 */
export function deliveryErrorText(error: string | null, responseStatus: number | null): string | null {
  if (!error) return null
  const reasons = copy.webhooks.deliveries.errors as Record<string, string>
  const [code = '', ...rest] = error.split(':')
  const detail = rest.join(':').trim()
  if (code === 'http_status') return fill(reasons.http_status ?? error, { status: responseStatus ?? '?' })
  const sentence = reasons[code.trim()]
  if (!sentence) return error
  return detail ? `${sentence} (${detail})` : sentence
}

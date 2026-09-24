import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { fixtureId, NOW, nextId } from './data'
import { apiUrl, problem } from './handlers'
import { validationFailed } from './list'

type Webhook = components['schemas']['WebhookSubscriptionResource']
type Delivery = components['schemas']['WebhookDeliveryResource']
type EventType = components['schemas']['WebhookEventTypeResource']
type StoreInput = components['schemas']['StoreWebhookRequest']

/** The catalogue of `GET /v1/webhooks/events` (docs/07-api/webhooks.md §Event catalogue). */
export const WEBHOOK_EVENTS: EventType[] = [
  { type: 'ticket.created', description: 'A ticket was created' },
  { type: 'ticket.updated', description: 'Ticket fields were edited' },
  { type: 'ticket.assigned', description: 'A ticket was assigned or reassigned' },
  { type: 'ticket.status_changed', description: 'A ticket changed status' },
  { type: 'ticket.priority_changed', description: "A ticket's priority level changed" },
  { type: 'ticket.resolved', description: 'A ticket was resolved' },
  { type: 'ticket.closed', description: 'A ticket was closed' },
  { type: 'ticket.comment_added', description: 'A public reply was added to a ticket' },
  { type: 'ticket.sla_breached', description: 'An SLA timer of a ticket was breached' },
  { type: 'contact.created', description: 'A contact was created' },
  { type: 'contact.updated', description: 'A contact was updated' },
]

/** Secrets the create and rotate handlers return; tests look for them on screen. */
export const TEST_WEBHOOK_SECRET = 'mock-webhook-secret-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export const TEST_ROTATED_SECRET = 'mock-rotated-secret-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB='

const WEBHOOK_KIND = 0xb0
const DELIVERY_KIND = 0xb1

const WEBHOOK_FIXTURES: Webhook[] = [
  {
    id: fixtureId(WEBHOOK_KIND, 1),
    name: 'CRM sync',
    url: 'https://crm.example.com/hooks/helpdesk',
    events: ['ticket.created', 'ticket.resolved'],
    api_version: 'v1',
    is_active: true,
    disabled_at: null,
    disabled_reason: null,
    consecutive_failures: 2,
    previous_secret_expires_at: null,
    last_delivery_at: '2026-09-18T08:30:00Z',
    created_at: '2026-09-10T08:00:00Z',
    updated_at: '2026-09-18T08:30:00Z',
  },
  {
    id: fixtureId(WEBHOOK_KIND, 2),
    name: 'Old chat bot',
    url: 'https://bot.example.org/in',
    events: ['ticket.comment_added'],
    api_version: 'v1',
    is_active: false,
    disabled_at: '2026-09-15T10:00:00Z',
    disabled_reason: 'consecutive_failures',
    consecutive_failures: 20,
    previous_secret_expires_at: null,
    last_delivery_at: '2026-09-15T10:00:00Z',
    created_at: '2026-09-01T08:00:00Z',
    updated_at: '2026-09-15T10:00:00Z',
  },
]

function delivery(index: number, webhookId: string, overrides: Partial<Delivery>): Delivery {
  return {
    id: fixtureId(DELIVERY_KIND, index),
    subscription_id: webhookId,
    event_id: fixtureId(DELIVERY_KIND, 100 + index),
    event_type: 'ticket.created',
    state: 'succeeded',
    attempt: 1,
    manual_retries: 0,
    next_attempt_at: null,
    last_attempted_at: '2026-09-18T08:30:00Z',
    response_status: 200,
    response_excerpt: 'ok',
    error: null,
    duration_ms: 120,
    created_at: '2026-09-18T08:30:00Z',
    ...overrides,
  }
}

const DELIVERY_FIXTURES: Delivery[] = [
  delivery(1, fixtureId(WEBHOOK_KIND, 1), {
    event_type: 'ticket.resolved',
    created_at: '2026-09-18T08:30:00Z',
  }),
  delivery(2, fixtureId(WEBHOOK_KIND, 1), {
    event_type: 'ticket.created',
    state: 'dead',
    attempt: 6,
    response_status: 503,
    response_excerpt: 'down',
    error: 'http_status',
    last_attempted_at: '2026-09-17T20:00:00Z',
    created_at: '2026-09-17T07:00:00Z',
  }),
  // Failed on its second attempt, with the third scheduled: "Retrying" in the UI (M4-11).
  delivery(3, fixtureId(WEBHOOK_KIND, 1), {
    event_type: 'ticket.assigned',
    state: 'failed',
    attempt: 2,
    response_status: null,
    response_excerpt: null,
    error: 'timeout',
    duration_ms: 10000,
    next_attempt_at: '2026-09-18T09:00:00Z',
    last_attempted_at: '2026-09-18T08:25:00Z',
    created_at: '2026-09-18T08:20:00Z',
  }),
]

/** The payload a delivery carries, as the backend's `WebhookPayload` shapes it (id, type, data). */
export function deliveryPayload(item: Delivery) {
  return {
    id: item.event_id,
    type: item.event_type,
    api_version: 'v1',
    created_at: item.created_at,
    tenant_id: fixtureId(1, 1),
    data: { ticket: { id: fixtureId(2, 1), number: 1001, title: 'VPN drops every hour', status: 'open' } },
  }
}

const clone = <T>(value: T): T => structuredClone(value)

/** Webhook state of the mock API; reset after every browser test by `resetWebhookData`. */
export const webhookDb = {
  webhooks: clone(WEBHOOK_FIXTURES),
  deliveries: clone(DELIVERY_FIXTURES),
}

export function resetWebhookData(): void {
  webhookDb.webhooks = clone(WEBHOOK_FIXTURES)
  webhookDb.deliveries = clone(DELIVERY_FIXTURES)
}

function findWebhook(id: unknown): Webhook | undefined {
  return webhookDb.webhooks.find((webhook) => webhook.id === id)
}

function validate(input: Partial<StoreInput>, partial: boolean): Record<string, string[]> {
  const errors: Record<string, string[]> = {}
  if ((!partial || input.name !== undefined) && (!input.name || input.name.trim() === ''))
    errors.name = ['The name field is required.']
  if ((!partial || input.url !== undefined) && (!input.url || !/^https?:\/\//.test(input.url)))
    errors.url = ['The url field must be a valid URL.']
  if ((!partial || input.events !== undefined) && (!Array.isArray(input.events) || input.events.length === 0))
    errors.events = ['The events field is required.']
  return errors
}

/** The API's SSRF guard, reduced to the addresses the tests use. */
function rejectedUrl(url: string): boolean {
  return /\/\/(10\.|127\.|169\.254\.|192\.168\.|localhost)/.test(url)
}

function urlRejected() {
  return problem(422, 'webhook_url_rejected', {
    title: 'The webhook URL is not allowed',
    detail: 'The webhook host resolves to a private, loopback or reserved address.',
    meta: { reason: 'private_address' },
    errors: { url: ['The webhook host resolves to a private, loopback or reserved address.'] },
  })
}

export const webhookHandlers = [
  http.get(apiUrl('/webhooks'), () =>
    HttpResponse.json({
      data: [...webhookDb.webhooks].sort((a, b) => b.created_at.localeCompare(a.created_at)),
    }),
  ),
  http.get(apiUrl('/webhooks/events'), () => HttpResponse.json({ data: WEBHOOK_EVENTS })),
  http.post(apiUrl('/webhooks'), async ({ request }) => {
    const input = (await request.json()) as StoreInput
    const errors = validate(input, false)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    if (rejectedUrl(input.url)) return urlRejected()

    const webhook: Webhook = {
      id: nextId(WEBHOOK_KIND),
      name: input.name.trim(),
      url: input.url,
      events: input.events,
      api_version: 'v1',
      is_active: true,
      disabled_at: null,
      disabled_reason: null,
      consecutive_failures: 0,
      previous_secret_expires_at: null,
      last_delivery_at: null,
      created_at: NOW,
      updated_at: NOW,
    }
    webhookDb.webhooks.push(webhook)
    return HttpResponse.json({ data: { ...webhook, secret: TEST_WEBHOOK_SECRET } }, { status: 201 })
  }),
  http.patch(apiUrl('/webhooks/{webhook}'), async ({ params, request }) => {
    const webhook = findWebhook(params.webhook)
    if (!webhook) return problem(404, 'not_found', { title: 'Not found' })
    const input = (await request.json()) as Partial<StoreInput>
    const errors = validate(input, true)
    if (Object.keys(errors).length > 0) return validationFailed(errors)
    if (input.url !== undefined && rejectedUrl(input.url)) return urlRejected()
    Object.assign(webhook, input, { updated_at: NOW })
    return HttpResponse.json({ data: webhook })
  }),
  http.delete(apiUrl('/webhooks/{webhook}'), ({ params }) => {
    const before = webhookDb.webhooks.length
    webhookDb.webhooks = webhookDb.webhooks.filter((webhook) => webhook.id !== params.webhook)
    if (webhookDb.webhooks.length === before) return problem(404, 'not_found', { title: 'Not found' })
    return new HttpResponse(null, { status: 204 })
  }),
  http.post(apiUrl('/webhooks/{webhook}/enable'), ({ params }) => {
    const webhook = findWebhook(params.webhook)
    if (!webhook) return problem(404, 'not_found', { title: 'Not found' })
    Object.assign(webhook, {
      is_active: true,
      disabled_at: null,
      disabled_reason: null,
      consecutive_failures: 0,
    })
    return HttpResponse.json({ data: webhook })
  }),
  http.post(apiUrl('/webhooks/{webhook}/disable'), ({ params }) => {
    const webhook = findWebhook(params.webhook)
    if (!webhook) return problem(404, 'not_found', { title: 'Not found' })
    Object.assign(webhook, { is_active: false, disabled_at: NOW, disabled_reason: 'manual' })
    return HttpResponse.json({ data: webhook })
  }),
  http.post(apiUrl('/webhooks/{webhook}/rotate-secret'), ({ params }) => {
    const webhook = findWebhook(params.webhook)
    if (!webhook) return problem(404, 'not_found', { title: 'Not found' })
    webhook.previous_secret_expires_at = '2026-09-19T09:00:00Z'
    return HttpResponse.json({ data: { ...webhook, secret: TEST_ROTATED_SECRET } })
  }),
  http.post(apiUrl('/webhooks/{webhook}/test'), ({ params }) => {
    const webhook = findWebhook(params.webhook)
    if (!webhook) return problem(404, 'not_found', { title: 'Not found' })
    const ping = delivery(0, webhook.id, {
      id: nextId(DELIVERY_KIND),
      event_type: 'ping',
      state: 'pending',
      attempt: 0,
      last_attempted_at: null,
      response_status: null,
      response_excerpt: null,
      duration_ms: null,
      next_attempt_at: NOW,
      created_at: NOW,
    })
    webhookDb.deliveries.unshift(ping)
    return HttpResponse.json({ data: ping }, { status: 202 })
  }),
  http.get(apiUrl('/webhooks/{webhook}/deliveries'), ({ params, request }) => {
    if (!findWebhook(params.webhook)) return problem(404, 'not_found', { title: 'Not found' })
    const state = new URL(request.url).searchParams.get('filter[state]')
    const data = webhookDb.deliveries
      .filter((item) => item.subscription_id === params.webhook)
      .filter((item) => state === null || item.state === state)
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
    return HttpResponse.json({
      data,
      links: { first: null, last: null, prev: null, next: null },
      meta: { path: null, per_page: 25, next_cursor: null, prev_cursor: null },
    })
  }),
  http.get(apiUrl('/webhook-deliveries/{delivery}'), ({ params }) => {
    const item = webhookDb.deliveries.find((candidate) => candidate.id === params.delivery)
    if (!item) return problem(404, 'not_found', { title: 'Not found' })
    return HttpResponse.json({ data: { ...item, payload: deliveryPayload(item) } })
  }),
  http.post(apiUrl('/webhook-deliveries/{delivery}/retry'), ({ params }) => {
    const item = webhookDb.deliveries.find((candidate) => candidate.id === params.delivery)
    if (!item) return problem(404, 'not_found', { title: 'Not found' })
    if (item.state !== 'failed' && item.state !== 'dead')
      return problem(409, 'delivery_not_retryable', { title: 'The delivery cannot be retried' })
    Object.assign(item, { state: 'pending', manual_retries: item.manual_retries + 1, next_attempt_at: NOW })
    return HttpResponse.json({ data: item }, { status: 202 })
  }),
]

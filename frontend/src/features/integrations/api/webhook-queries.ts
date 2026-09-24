import { infiniteQueryOptions, queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type Webhook = components['schemas']['WebhookSubscriptionResource']
export type WebhookWithSecret = components['schemas']['WebhookSubscriptionWithSecretResource']
export type WebhookEventType = components['schemas']['WebhookEventTypeResource']
export type WebhookDelivery = components['schemas']['WebhookDeliveryResource']
export type WebhookDeliveryDetail = components['schemas']['WebhookDeliveryDetailResource']
export type DeliveryState = components['schemas']['DeliveryState']
export type WebhookInput = components['schemas']['StoreWebhookRequest']

export const DELIVERY_PAGE_SIZE = 25

export const webhookQueries = {
  list: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.webhooks.list(tenantId),
      queryFn: () => unwrap(api().GET('/webhooks')),
    }),
  events: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.webhooks.events(tenantId),
      queryFn: () => unwrap(api().GET('/webhooks/events')),
      staleTime: Number.POSITIVE_INFINITY,
    }),
  /** The delivery log is a cursor feed, newest first (docs/07-api/conventions.md). */
  deliveries: (tenantId: string, webhookId: string, state?: DeliveryState) =>
    infiniteQueryOptions({
      queryKey: queryKeys.webhooks.deliveries(tenantId, webhookId, state ?? 'all'),
      initialPageParam: undefined as string | undefined,
      queryFn: ({ pageParam }) =>
        unwrapBody(
          api().GET('/webhooks/{webhook}/deliveries', {
            params: {
              path: { webhook: webhookId },
              query: {
                per_page: DELIVERY_PAGE_SIZE,
                cursor: pageParam,
                ...(state ? { 'filter[state]': state } : {}),
              },
            },
          }),
        ),
      getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    }),
  /** One delivery with its payload (M4-11). The signing secret and request headers are never sent. */
  delivery: (tenantId: string, deliveryId: string) =>
    queryOptions({
      queryKey: queryKeys.webhooks.delivery(tenantId, deliveryId),
      queryFn: () =>
        unwrap(api().GET('/webhook-deliveries/{delivery}', { params: { path: { delivery: deliveryId } } })),
    }),
}

export const createWebhook = (input: WebhookInput) => unwrap(api().POST('/webhooks', { body: input }))

export const updateWebhook = (id: string, input: Partial<WebhookInput>) =>
  unwrap(api().PATCH('/webhooks/{webhook}', { params: { path: { webhook: id } }, body: input }))

export const deleteWebhook = (id: string) =>
  unwrapBody(api().DELETE('/webhooks/{webhook}', { params: { path: { webhook: id } } }))

export const setWebhookActive = (id: string, active: boolean) =>
  unwrap(
    active
      ? api().POST('/webhooks/{webhook}/enable', { params: { path: { webhook: id } } })
      : api().POST('/webhooks/{webhook}/disable', { params: { path: { webhook: id } } }),
  )

export const rotateWebhookSecret = (id: string) =>
  unwrap(api().POST('/webhooks/{webhook}/rotate-secret', { params: { path: { webhook: id } } }))

export const testWebhook = (id: string) =>
  unwrap(api().POST('/webhooks/{webhook}/test', { params: { path: { webhook: id } } }))

export const retryWebhookDelivery = (id: string) =>
  unwrap(api().POST('/webhook-deliveries/{delivery}/retry', { params: { path: { delivery: id } } }))

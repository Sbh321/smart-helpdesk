import { type QueryClient, queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'

export type NotificationItem = components['schemas']['NotificationResource']

/**
 * How often the bell asks for the unread count (roadmap M2-09: "bell count updates within 30 s").
 * MVP-SHORTCUT: polling; V1: M3-16 Reverb realtime on `tenants.{tenant}.users.{user}` (docs/03-architecture/realtime.md).
 */
export const NOTIFICATION_POLL_MS = 30_000

export interface NotificationListQuery {
  page: number
  perPage: number
  unreadOnly: boolean
}

export const notificationQueries = {
  list: (tenantId: string, query: NotificationListQuery) =>
    queryOptions({
      queryKey: queryKeys.notifications.list(tenantId, query),
      queryFn: () =>
        unwrapBody(
          api().GET('/notifications', {
            params: {
              query: {
                page: query.page,
                per_page: query.perPage,
                ...(query.unreadOnly ? { 'filter[unread]': 'true' } : {}),
              },
            },
          }),
        ),
      refetchInterval: NOTIFICATION_POLL_MS,
    }),
  /** The unread total, read from the pagination meta of a one-row page. */
  unreadCount: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.notifications.unread(tenantId),
      queryFn: async () => {
        const body = await unwrapBody(
          api().GET('/notifications', { params: { query: { per_page: 1, 'filter[unread]': 'true' } } }),
        )
        return body.meta.total
      },
      refetchInterval: NOTIFICATION_POLL_MS,
      refetchIntervalInBackground: false,
    }),
}

async function refresh(client: QueryClient, tenantId: string): Promise<void> {
  await Promise.all([
    client.invalidateQueries({ queryKey: queryKeys.notifications.all(tenantId) }),
    client.invalidateQueries({ queryKey: queryKeys.session.me() }),
  ])
}

export async function markNotificationRead(
  client: QueryClient,
  tenantId: string,
  id: string,
): Promise<NotificationItem> {
  const item = await unwrap(
    api().POST('/notifications/{notification}/read', { params: { path: { notification: id } } }),
  )
  await refresh(client, tenantId)
  return item
}

export async function markAllNotificationsRead(client: QueryClient, tenantId: string): Promise<void> {
  await unwrapBody(api().POST('/notifications/read-all'))
  await refresh(client, tenantId)
}

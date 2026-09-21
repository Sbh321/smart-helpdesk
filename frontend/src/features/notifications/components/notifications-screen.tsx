import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/shared/empty-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import {
  markAllNotificationsRead,
  markNotificationRead,
  type NotificationItem,
  notificationQueries,
} from '../api/notification-queries'
import { NotificationRow } from './notification-row'

const text = copy.notifications
const PER_PAGE = 25

/** `/$workspace/notifications`: every notification of the signed-in user, unread first. */
export function NotificationsScreen({ workspace }: { workspace: string }) {
  const client = useQueryClient()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)

  const list = useQuery({
    ...notificationQueries.list(tenantId, { page, perPage: PER_PAGE, unreadOnly }),
    enabled: tenantId !== '',
  })
  const items = list.data?.data ?? []
  const pages = Math.max(1, list.data?.meta.last_page ?? 1)
  const hasUnread = items.some((item) => item.read_at === null)

  const markRead = async (item: NotificationItem) => {
    if (item.read_at !== null) return
    try {
      await markNotificationRead(client, tenantId, item.id)
    } catch {
      toast.error(text.markReadFailed)
    }
  }

  return (
    <div className="flex max-w-3xl flex-col gap-4">
      <PageHeader
        title={text.title}
        description={text.intro}
        actions={
          <Button
            variant="outline"
            disabled={!hasUnread}
            onClick={() =>
              void markAllNotificationsRead(client, tenantId).catch(() => toast.error(text.markReadFailed))
            }
          >
            {text.markAllRead}
          </Button>
        }
      />
      <div className="flex items-center gap-2">
        <Switch
          id="notifications-unread-only"
          checked={unreadOnly}
          onCheckedChange={(next) => {
            setUnreadOnly(next)
            setPage(1)
          }}
        />
        <label htmlFor="notifications-unread-only" className="text-sm">
          {text.unreadOnly}
        </label>
      </div>
      {list.isError ? <FormErrorBanner title={text.loadFailed} error={list.error} /> : null}
      {list.isSuccess && items.length === 0 ? (
        <EmptyState title={unreadOnly ? text.emptyUnread : text.empty} />
      ) : null}
      {items.length > 0 ? (
        <ul aria-label={text.title} className="flex flex-col gap-2" aria-busy={list.isFetching}>
          {items.map((item) => (
            <NotificationRow
              key={item.id}
              item={item}
              workspace={workspace}
              timeZone={timeZone}
              onOpen={(opened) => void markRead(opened)}
              onMarkRead={(target) => void markRead(target)}
            />
          ))}
        </ul>
      ) : null}
      {pages > 1 ? (
        <nav aria-label={text.title} className="flex items-center gap-3">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {text.previous}
          </Button>
          <span className="text-sm text-muted-foreground">{fill(text.page, { page, pages })}</span>
          <Button variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {text.next}
          </Button>
        </nav>
      ) : null}
    </div>
  )
}

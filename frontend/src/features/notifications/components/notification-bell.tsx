import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { BellIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverDescription,
  PopoverHeader,
  PopoverTitle,
  PopoverTrigger,
} from '@/components/ui/popover'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import {
  markAllNotificationsRead,
  markNotificationRead,
  type NotificationItem,
  notificationQueries,
} from '../api/notification-queries'
import { NotificationRow } from './notification-row'

const shell = copy.shell.notifications
const text = copy.notifications

/**
 * The bell (docs/06-design-system/components.md §NotificationBell): unread badge polled every 30 s,
 * a popover with the latest 10 and mark-read, a link to all of them. A rising count is announced once
 * in a polite live region.
 */
export function NotificationBell({ workspace }: { workspace: string }) {
  const client = useQueryClient()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const [open, setOpen] = useState(false)

  const count = useQuery({ ...notificationQueries.unreadCount(tenantId), enabled: tenantId !== '' })
  const unread = count.data ?? session?.unread_notifications ?? 0
  const latest = useQuery({
    ...notificationQueries.list(tenantId, { page: 1, perPage: 10, unreadOnly: false }),
    enabled: open && tenantId !== '',
  })

  // Announce only a rise after the first reading, so signing in does not read the backlog out loud.
  const previous = useRef<number | null>(null)
  const [announcement, setAnnouncement] = useState('')
  useEffect(() => {
    if (count.data === undefined) return
    if (previous.current !== null && count.data > previous.current) {
      setAnnouncement(fill(shell.announce, { count: count.data - previous.current }))
    }
    previous.current = count.data
  }, [count.data])

  const markRead = async (item: NotificationItem) => {
    if (item.read_at !== null) return
    try {
      await markNotificationRead(client, tenantId, item.id)
    } catch {
      toast.error(text.markReadFailed)
    }
  }

  const countText = fill(shell.count, { count: unread })
  const label = unread > 0 ? `${shell.label}: ${countText}` : shell.label
  const items = latest.data?.data ?? []

  return (
    <>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger
          render={<Button variant="ghost" size="icon-sm" aria-label={label} className="relative" />}
        >
          <BellIcon aria-hidden="true" />
          {unread > 0 ? (
            <span className="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-4 font-medium text-primary-foreground">
              {unread > 99 ? '99+' : unread}
            </span>
          ) : null}
        </PopoverTrigger>
        <PopoverContent align="end" className="w-80">
          <PopoverHeader>
            <PopoverTitle>{shell.label}</PopoverTitle>
            <PopoverDescription>{unread > 0 ? countText : shell.none}</PopoverDescription>
          </PopoverHeader>
          {items.length > 0 ? (
            <ul aria-label={text.latest} className="max-h-80 divide-y divide-border overflow-y-auto">
              {items.map((item) => (
                <NotificationRow
                  key={item.id}
                  item={item}
                  workspace={workspace}
                  timeZone={timeZone}
                  compact
                  onOpen={(opened) => {
                    setOpen(false)
                    void markRead(opened)
                  }}
                  onMarkRead={(target) => void markRead(target)}
                />
              ))}
            </ul>
          ) : null}
          <div className="flex items-center justify-between gap-2 border-t border-border pt-2">
            <Link
              to="/$workspace/notifications"
              params={{ workspace }}
              className="text-sm text-primary underline-offset-2 hover:underline"
              onClick={() => setOpen(false)}
            >
              {text.viewAll}
            </Link>
            {unread > 0 ? (
              <Button
                variant="ghost"
                size="sm"
                onClick={() =>
                  void markAllNotificationsRead(client, tenantId).catch(() =>
                    toast.error(text.markReadFailed),
                  )
                }
              >
                {text.markAllRead}
              </Button>
            ) : null}
          </div>
        </PopoverContent>
      </Popover>
      {/* accessibility.md §Live regions: new notifications are announced politely. */}
      <span className="sr-only" role="status" aria-live="polite">
        {announcement}
      </span>
    </>
  )
}

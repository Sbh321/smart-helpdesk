import { Link } from '@tanstack/react-router'
import { copy, fill } from '@/copy/en'
import { apiUrl } from '@/lib/api/client'
import { formatInZone } from '@/lib/datetime/format'
import { cn } from '@/lib/utils'
import type { NotificationItem } from '../api/notification-queries'

const text = copy.notifications

/** The sentence of a notification: our own copy per kind, the API's summary for a kind we do not know. */
export function notificationText(item: NotificationItem): string {
  return text.kinds[item.kind] ?? item.summary
}

/**
 * One notification: what happened, on which ticket (or which export file), when. Opening the ticket or
 * the file marks it read; unread rows carry a visible dot and the word "Unread" for screen readers.
 */
export function NotificationRow({
  item,
  workspace,
  timeZone,
  onOpen,
  onMarkRead,
  compact = false,
}: {
  item: NotificationItem
  workspace: string
  timeZone: string
  onOpen: (item: NotificationItem) => void
  onMarkRead?: (item: NotificationItem) => void
  compact?: boolean
}) {
  const unread = item.read_at === null
  return (
    <li
      className={cn(
        'flex items-start gap-3 rounded-md',
        compact ? 'px-1 py-2' : 'border border-border bg-surface p-3',
      )}
    >
      <span
        aria-hidden="true"
        className={cn('mt-1.5 size-2 shrink-0 rounded-full', unread ? 'bg-primary' : 'bg-transparent')}
      />
      <div className="min-w-0 flex-1 space-y-0.5">
        <p className={cn('text-sm', unread && 'font-medium')}>
          {unread ? <span className="sr-only">{text.unread}: </span> : null}
          {notificationText(item)}
        </p>
        {item.ticket_id !== null ? (
          <Link
            to="/$workspace/tickets/$ticketId"
            params={{ workspace, ticketId: item.ticket_id }}
            className="block truncate text-sm text-primary underline-offset-2 hover:underline"
            onClick={() => onOpen(item)}
          >
            {fill(text.ticket, { number: item.ticket_number ?? '', title: item.ticket_title ?? '' })}
          </Link>
        ) : item.media_id !== null ? (
          // An export (M3-09): the Media download route signs a fresh short-lived URL on each click.
          <a
            href={apiUrl(`/v1/media/${item.media_id}/download`)}
            className="block truncate text-sm text-primary underline-offset-2 hover:underline"
            onClick={() => onOpen(item)}
          >
            {fill(text.download, { name: item.file_name ?? '' })}
          </a>
        ) : null}
        <p className="text-xs text-muted-foreground">
          <time dateTime={item.created_at}>{formatInZone(item.created_at, timeZone)}</time>
        </p>
      </div>
      {unread && onMarkRead ? (
        <button
          type="button"
          aria-label={`${text.markRead}: ${notificationText(item)}`}
          className="shrink-0 rounded-sm text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          onClick={() => onMarkRead(item)}
        >
          {text.markRead}
        </button>
      ) : null}
    </li>
  )
}

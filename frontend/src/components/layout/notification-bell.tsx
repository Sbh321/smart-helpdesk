import { BellIcon } from 'lucide-react'
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

/**
 * Placeholder bell (docs/06-design-system/components.md §NotificationBell). The unread count comes from
 * the session; the list itself arrives with the notification centre (roadmap M2-16), so the popover says
 * so rather than pretending to be an empty inbox.
 */
export function NotificationBell() {
  const { session } = useSession()
  const unread = session?.unread_notifications ?? 0
  const countText = fill(copy.shell.notifications.count, { count: unread })
  const label =
    unread > 0 ? `${copy.shell.notifications.label}: ${countText}` : copy.shell.notifications.label

  return (
    <>
      <Popover>
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
        <PopoverContent align="end" className="w-72">
          <PopoverHeader>
            <PopoverTitle>{copy.shell.notifications.label}</PopoverTitle>
            <PopoverDescription>{unread > 0 ? countText : copy.shell.notifications.none}</PopoverDescription>
          </PopoverHeader>
          <p className="text-xs text-muted-foreground">{copy.shell.notifications.placeholder}</p>
        </PopoverContent>
      </Popover>
      {/* New notifications are announced politely; see accessibility.md §Live regions. */}
      <span className="sr-only" role="status" aria-live="polite">
        {unread > 0 ? countText : ''}
      </span>
    </>
  )
}

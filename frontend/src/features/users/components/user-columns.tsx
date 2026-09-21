import { TriangleAlertIcon } from 'lucide-react'
import { dataTableColumnHelper } from '@/components/shared/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import type { WorkspaceUser } from '../api/user-queries'
import { roleLabel } from '../schemas'

const helper = dataTableColumnHelper<WorkspaceUser>()
const text = copy.users

export interface UserRowActions {
  /** The signed-in user: their own row offers no disable. */
  selfId: string | undefined
  onEdit: (user: WorkspaceUser) => void
  onResend: (user: WorkspaceUser) => void
  onDisable: (user: WorkspaceUser) => void
  onEnable: (user: WorkspaceUser) => void
  /** The id of the row a request is running for; its buttons are disabled. */
  busyId: string | null
}

export function UserStatusBadge({ user, timeZone }: { user: WorkspaceUser; timeZone: string }) {
  if (user.status === 'invited' && user.invitation_expired) {
    // The destructive badge tint does not reach 4.5:1; the icon and the words carry the state.
    return (
      <Badge variant="outline" className="border-destructive">
        <TriangleAlertIcon aria-hidden="true" />
        {text.invitationExpired}
      </Badge>
    )
  }
  const variant = user.status === 'active' ? 'secondary' : 'outline'
  return (
    <span className="flex flex-col gap-1">
      <Badge variant={variant}>{text.statuses[user.status]}</Badge>
      {user.status === 'invited' && user.invitation_expires_at ? (
        <span className="text-xs text-muted-foreground">
          {fill(text.invitationExpires, { date: formatInZone(user.invitation_expires_at, timeZone) })}
        </span>
      ) : null}
    </span>
  )
}

/** Users table columns; the ids of sortable columns are the API's sort fields. */
export function userColumns(timeZone: string, actions: UserRowActions) {
  return helper.columns([
    helper.accessor('name', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: text.name, className: 'font-medium' },
      cell: (info) => {
        const user = info.row.original
        return (
          <span className="flex min-w-0 flex-col">
            <span className="truncate">
              {user.name}
              {user.id === actions.selfId ? (
                <span className="ml-2 text-xs font-normal text-muted-foreground">({text.you})</span>
              ) : null}
            </span>
            <span className="truncate text-xs font-normal text-muted-foreground">{user.email}</span>
          </span>
        )
      },
    }),
    helper.accessor('status', {
      meta: { label: text.status },
      cell: (info) => <UserStatusBadge user={info.row.original} timeZone={timeZone} />,
    }),
    helper.accessor('roles', {
      meta: { label: text.roles },
      cell: (info) => (
        <ul className="flex flex-wrap gap-1">
          {info.getValue().map((role) => (
            <li key={role}>
              <Badge variant="outline">{roleLabel(role)}</Badge>
            </li>
          ))}
        </ul>
      ),
    }),
    helper.accessor('last_login_at', {
      enableSorting: true,
      meta: { label: text.lastSignIn, className: 'tabular-nums' },
      cell: (info) => {
        const value = info.getValue()
        return value ? (
          formatInZone(value, timeZone)
        ) : (
          <span className="text-muted-foreground">{text.never}</span>
        )
      },
    }),
    helper.display({
      id: 'actions',
      enableHiding: false,
      meta: { label: text.actions, className: 'text-right' },
      cell: (info) => {
        const user = info.row.original
        const busy = actions.busyId === user.id
        return (
          <span className="flex flex-wrap justify-end gap-2">
            <Button
              size="sm"
              variant="outline"
              disabled={busy}
              aria-label={fill(text.editNamed, { name: user.name })}
              onClick={() => actions.onEdit(user)}
            >
              {text.edit}
            </Button>
            {user.status === 'invited' ? (
              <Button
                size="sm"
                variant="outline"
                disabled={busy}
                aria-label={fill(text.resendNamed, { name: user.name })}
                onClick={() => actions.onResend(user)}
              >
                {text.resend}
              </Button>
            ) : null}
            {user.status === 'disabled' ? (
              <Button
                size="sm"
                variant="outline"
                disabled={busy}
                aria-label={fill(text.enableNamed, { name: user.name })}
                onClick={() => actions.onEnable(user)}
              >
                {text.enable}
              </Button>
            ) : user.id !== actions.selfId ? (
              <Button
                size="sm"
                variant="destructive"
                disabled={busy}
                aria-label={fill(text.disableNamed, { name: user.name })}
                onClick={() => actions.onDisable(user)}
              >
                {text.disable}
              </Button>
            ) : null}
          </span>
        )
      },
    }),
  ])
}

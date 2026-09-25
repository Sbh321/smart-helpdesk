import { useQuery, useQueryClient } from '@tanstack/react-query'
import { EllipsisVerticalIcon, MailPlusIcon, UserPlusIcon } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { TextField } from '@/components/shared/text-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { formatInZone } from '@/lib/datetime/format'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type PlatformAdmin, platform, platformAdminsQuery } from '../api'

const text = copy.platform.admins

type Pending = { kind: 'deactivate' | 'revoke'; admin: PlatformAdmin }

/** Platform admins (ADR-0025 §7): invite, send again, withdraw, deactivate and reactivate. */
export function AdminsScreen() {
  const admins = useQuery(platformAdminsQuery())
  const client = useQueryClient()
  const [inviting, setInviting] = useState(false)
  const [pending, setPending] = useState<Pending | null>(null)
  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.platform.admins() })

  const act = async (action: () => Promise<unknown>, message: string) => {
    try {
      await action()
      await refresh()
      toast.success(message)
    } catch (error) {
      toast.error(
        error instanceof Error && 'detail' in error && typeof error.detail === 'string'
          ? error.detail
          : text.failed,
      )
    }
  }

  return (
    <>
      <PageHeader
        title={text.title}
        description={text.description}
        actions={
          <Button type="button" onClick={() => setInviting(true)}>
            <UserPlusIcon aria-hidden="true" />
            {text.invite}
          </Button>
        }
      />
      {admins.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : admins.isError ? (
        <ErrorState error={admins.error} onRetry={() => void admins.refetch()} />
      ) : (
        <Table aria-label={text.tableLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{text.columns.name}</TableHead>
              <TableHead>{text.columns.email}</TableHead>
              <TableHead>{text.columns.status}</TableHead>
              <TableHead>{text.columns.lastSignIn}</TableHead>
              <TableHead>
                <span className="sr-only">{copy.dataTable.bulkActions}</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {admins.data.map((admin) => (
              <TableRow key={admin.id}>
                <TableCell className="font-medium">
                  {admin.name}
                  {admin.is_you ? <span className="text-muted-foreground"> ({text.you})</span> : null}
                </TableCell>
                <TableCell>{admin.email}</TableCell>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <Badge variant={admin.status === 'active' ? 'secondary' : 'outline'} className="w-fit">
                      {text.statuses[admin.status]}
                    </Badge>
                    {admin.invitation_expires_at ? (
                      <span className="text-muted-foreground text-xs">
                        {fill(text.expires, {
                          date: formatInZone(admin.invitation_expires_at, 'UTC', 'd MMM, HH:mm'),
                        })}
                      </span>
                    ) : null}
                  </span>
                </TableCell>
                <TableCell className="tabular-nums">
                  {admin.last_login_at
                    ? formatInZone(admin.last_login_at, 'UTC', 'd MMM yyyy, HH:mm')
                    : text.never}
                </TableCell>
                <TableCell className="text-right">
                  {admin.is_you ? null : (
                    <DropdownMenu>
                      <Hint label={fill(text.actionsFor, { name: admin.name })}>
                        <DropdownMenuTrigger
                          render={
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon-sm"
                              aria-label={fill(text.actionsFor, { name: admin.name })}
                            />
                          }
                        >
                          <EllipsisVerticalIcon aria-hidden="true" />
                        </DropdownMenuTrigger>
                      </Hint>
                      <DropdownMenuContent align="end" className="min-w-48">
                        {admin.status === 'invited' ? (
                          <>
                            <DropdownMenuItem
                              onClick={() => void act(() => platform.resendInvitation(admin.id), text.resent)}
                            >
                              <MailPlusIcon aria-hidden="true" />
                              {text.resend}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              variant="destructive"
                              onClick={() => setPending({ kind: 'revoke', admin })}
                            >
                              {text.revoke}
                            </DropdownMenuItem>
                          </>
                        ) : admin.status === 'active' ? (
                          <DropdownMenuItem
                            variant="destructive"
                            onClick={() => setPending({ kind: 'deactivate', admin })}
                          >
                            {text.deactivate}
                          </DropdownMenuItem>
                        ) : (
                          <DropdownMenuItem
                            onClick={() =>
                              void act(
                                () => platform.reactivateAdmin(admin.id),
                                fill(text.reactivated, { name: admin.name }),
                              )
                            }
                          >
                            {text.reactivate}
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <InviteDialog open={inviting} onClose={() => setInviting(false)} onInvited={refresh} />
      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null)
        }}
        destructive
        title={
          pending?.kind === 'revoke'
            ? fill(text.revokeTitle, { email: pending.admin.email })
            : fill(text.deactivateTitle, { name: pending?.admin.name ?? '' })
        }
        description={pending?.kind === 'revoke' ? text.revokeBody : text.deactivateBody}
        confirmLabel={pending?.kind === 'revoke' ? text.revoke : text.deactivate}
        failedTitle={text.failed}
        onConfirm={async () => {
          if (!pending) return
          if (pending.kind === 'revoke') await platform.revokeInvitation(pending.admin.id)
          else await platform.deactivateAdmin(pending.admin.id)
          await refresh()
          toast.success(
            pending.kind === 'revoke' ? text.revoked : fill(text.deactivated, { name: pending.admin.name }),
          )
        }}
      />
    </>
  )
}

function InviteDialog({
  open,
  onClose,
  onInvited,
}: {
  open: boolean
  onClose: () => void
  onInvited: () => Promise<void>
}) {
  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{text.inviteTitle}</DialogTitle>
          <DialogDescription>{text.inviteDescription}</DialogDescription>
        </DialogHeader>
        {open ? <InviteForm onClose={onClose} onInvited={onInvited} /> : null}
      </DialogContent>
    </Dialog>
  )
}

function InviteForm({ onClose, onInvited }: { onClose: () => void; onInvited: () => Promise<void> }) {
  const server = useServerErrors()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await platform.inviteAdmin({ name: name.trim(), email: email.trim() })
      await onInvited()
      toast.success(fill(text.invited, { email: email.trim() }))
      onClose()
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }
  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-4">
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <TextField
        id="invite-name"
        label={text.name}
        value={name}
        onValueChange={setName}
        errors={server.fields.name}
        autoComplete="off"
      />
      <TextField
        id="invite-email"
        label={text.email}
        type="email"
        value={email}
        onValueChange={setEmail}
        errors={server.fields.email}
        autoComplete="off"
      />
      <DialogFooter>
        <Button type="button" variant="outline" disabled={busy} onClick={onClose}>
          {copy.media.cancel}
        </Button>
        <Button type="submit" disabled={busy}>
          {text.send}
        </Button>
      </DialogFooter>
    </form>
  )
}

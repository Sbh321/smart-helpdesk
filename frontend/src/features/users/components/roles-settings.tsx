import { useQuery, useQueryClient } from '@tanstack/react-query'
import { PlusIcon } from 'lucide-react'
import { useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { SettingsPage } from '@/components/shared/settings-page'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { ApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { deleteRole, type Role, roleQueries } from '../api/user-queries'
import { permissionLabel, roleHolders, roleLabel } from '../schemas'
import { RoleEditorDialog, type RoleEditorTarget } from './role-editor-dialog'

const text = copy.roles

/** Global defaults (and any system role) cannot be edited or deleted. */
function isReadOnly(role: Role): boolean {
  return role.is_global || role.is_system
}

function RoleCard({
  role,
  onEdit,
  onDelete,
}: {
  role: Role
  onEdit?: (role: Role) => void
  onDelete?: (role: Role) => void
}) {
  const headingId = `role-${role.id}-name`
  return (
    <li className="space-y-3 rounded-lg border border-border p-4 bg-surface" aria-labelledby={headingId}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h4 id={headingId} className="font-medium">
            {roleLabel(role.name)}
          </h4>
          {isReadOnly(role) ? <Badge variant="secondary">{text.readOnly}</Badge> : null}
          <span className="text-xs text-muted-foreground">
            {fill(text.permissionCount, { count: role.permissions.length })}
          </span>
        </div>
        {onEdit || onDelete ? (
          <div className="flex gap-2">
            {onEdit ? (
              <Button
                size="sm"
                variant="outline"
                aria-label={fill(text.editNamed, { name: role.name })}
                onClick={() => onEdit(role)}
              >
                {copy.users.edit}
              </Button>
            ) : null}
            {onDelete ? (
              <Button
                size="sm"
                variant="destructive"
                aria-label={fill(text.deleteNamed, { name: role.name })}
                onClick={() => onDelete(role)}
              >
                {text.deleteAction}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
      <ul
        className="flex flex-wrap gap-1"
        aria-label={fill(text.permissionCount, { count: role.permissions.length })}
      >
        {role.permissions.map((permission) => (
          <li key={permission}>
            <Badge variant="outline">{permissionLabel(permission)}</Badge>
          </li>
        ))}
      </ul>
    </li>
  )
}

/**
 * 409 `in_use` names how many users hold the role; the dialog words it itself rather than showing the
 * problem detail.
 */
function explainDeleteFailure(error: unknown): unknown {
  const holders = roleHolders(error)
  if (holders === undefined || !(error instanceof ApiError)) return error
  return new ApiError({
    status: error.status,
    code: error.code,
    title: error.title,
    detail: holders === 1 ? text.inUseOne : fill(text.inUse, { count: holders }),
    requestId: error.requestId,
    meta: error.meta,
    cause: error,
  })
}

/** Settings → Roles (roadmap M2-12): default roles read-only, custom roles created, edited and deleted. */
export function RolesSettings() {
  const tenantId = useSession().session?.tenant.id ?? ''
  const allowed = useCan('roles.manage')
  const client = useQueryClient()
  const roles = useQuery({ ...roleQueries.list(tenantId), enabled: allowed && tenantId !== '' })
  const [editing, setEditing] = useState<RoleEditorTarget>(undefined)
  const [deleting, setDeleting] = useState<Role | null>(null)

  if (!allowed) return <ForbiddenState />

  const defaults = roles.data?.filter(isReadOnly) ?? []
  const custom = roles.data?.filter((role) => !isReadOnly(role)) ?? []

  return (
    <SettingsPage
      title={text.title}
      description={text.intro}
      actions={
        <Button onClick={() => setEditing(null)}>
          <PlusIcon aria-hidden="true" />
          {text.create}
        </Button>
      }
    >
      {roles.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : roles.isError ? (
        <ErrorState error={roles.error} onRetry={() => void roles.refetch()} />
      ) : (
        <>
          <section className="space-y-3" aria-labelledby="roles-default-heading">
            <h3 id="roles-default-heading" className="font-semibold">
              {text.defaults}
            </h3>
            <ul className="space-y-3">
              {defaults.map((role) => (
                <RoleCard key={role.id} role={role} />
              ))}
            </ul>
          </section>
          <section className="space-y-3" aria-labelledby="roles-custom-heading">
            <h3 id="roles-custom-heading" className="font-semibold">
              {text.custom}
            </h3>
            {custom.length === 0 ? (
              <p className="text-sm text-muted-foreground">{text.customEmpty}</p>
            ) : (
              <ul className="space-y-3">
                {custom.map((role) => (
                  <RoleCard key={role.id} role={role} onEdit={setEditing} onDelete={setDeleting} />
                ))}
              </ul>
            )}
          </section>
        </>
      )}
      <RoleEditorDialog target={editing} onClose={() => setEditing(undefined)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        destructive
        title={text.deleteTitle}
        description={fill(text.deleteBody, { name: deleting?.name ?? '' })}
        confirmLabel={text.deleteAction}
        failedTitle={text.deleteFailed}
        onConfirm={async () => {
          if (!deleting) return
          try {
            await deleteRole(deleting.id)
          } catch (error) {
            throw explainDeleteFailure(error)
          }
          await client.invalidateQueries({ queryKey: queryKeys.roles.all(tenantId) })
          toast.success(fill(text.deleted, { name: deleting.name }))
        }}
      />
    </SettingsPage>
  )
}

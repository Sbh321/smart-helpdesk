import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { UserPlusIcon, UsersIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import {
  DataTable,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SettingsPage } from '@/components/shared/settings-page'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { useListParams } from '@/lib/list-params'
import {
  disableUser,
  enableUser,
  resendInvitation,
  USER_STATUSES,
  userListSchema,
  userQueries,
  type WorkspaceUser,
} from '../api/user-queries'
import { userColumns } from './user-columns'
import { EditUserDialog, InviteUserDialog, useRoleOptions } from './user-dialogs'

const text = copy.users
const ALL_ROLES = 'all'
const STATUS_OPTIONS: FilterOption[] = USER_STATUSES.map((status) => ({
  value: status,
  label: text.statuses[status],
}))

/** Settings → Users (roadmap M2-12): invite, edit roles, resend invitations, disable and enable. */
export function UsersSettings() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const selfId = session?.user.id
  const allowed = useCan('users.manage')
  const client = useQueryClient()
  const list = useListParams(userListSchema)
  const users = useQuery({
    ...userQueries.list(tenantId, list.apiQuery),
    enabled: allowed && tenantId !== '',
  })
  const roleOptions = useRoleOptions()
  const [inviting, setInviting] = useState(false)
  const [editing, setEditing] = useState<WorkspaceUser | null>(null)
  const [disabling, setDisabling] = useState<WorkspaceUser | null>(null)

  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.users.all(tenantId) })
  const rowAction = useMutation({
    mutationFn: async ({ user, kind }: { user: WorkspaceUser; kind: 'resend' | 'enable' }) =>
      kind === 'resend' ? resendInvitation(user.id) : enableUser(user.id),
    onSuccess: async (_saved, { user, kind }) => {
      await refresh()
      toast.success(
        kind === 'resend'
          ? fill(text.resent, { email: user.email })
          : fill(text.enabled, { name: user.name }),
      )
    },
  })

  const columns = useMemo(
    () =>
      userColumns(timeZone, {
        selfId,
        onEdit: setEditing,
        onDisable: setDisabling,
        onResend: (user) => rowAction.mutate({ user, kind: 'resend' }),
        onEnable: (user) => rowAction.mutate({ user, kind: 'enable' }),
        busyId: rowAction.isPending ? (rowAction.variables?.user.id ?? null) : null,
      }),
    [timeZone, selfId, rowAction.mutate, rowAction.isPending, rowAction.variables],
  )

  if (!allowed) return <ForbiddenState />

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={UsersIcon}
        title={text.noMatchesTitle}
        description={text.noMatchesBody}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState icon={UsersIcon} title={text.emptyTitle} description={text.emptyBody} />
    )

  return (
    <SettingsPage
      title={text.title}
      description={text.intro}
      actions={
        <Button onClick={() => setInviting(true)}>
          <UserPlusIcon aria-hidden="true" />
          {text.invite}
        </Button>
      }
    >
      {rowAction.error ? <FormErrorBanner title={text.actionFailed} error={rowAction.error} /> : null}
      <DataTable
        id="settings-users"
        label={text.tableLabel}
        columns={columns}
        data={users.data?.data}
        rowCount={users.data?.meta.total}
        state={list.params}
        onStateChange={list.update}
        defaultSort={userListSchema.defaultSort}
        getRowId={(user) => user.id}
        getRowLabel={(user) => user.name}
        isFetching={users.isFetching && users.isPlaceholderData}
        error={users.error}
        onRetry={() => void users.refetch()}
        emptyState={emptyState}
        toolbar={
          <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
            <SearchFilter
              label={text.search}
              placeholder={text.searchPlaceholder}
              value={list.params.search}
              onChange={list.setSearch}
            />
            <MultiSelectFilter
              label={text.statusFilter}
              options={STATUS_OPTIONS}
              value={list.params.filters.status ?? []}
              onChange={(value) => list.setFilter('status', value as WorkspaceUser['status'][])}
            />
            <SelectFilter
              label={text.roleFilter}
              options={[{ value: ALL_ROLES, label: text.allRoles }, ...roleOptions]}
              value={list.params.filters.role?.[0]}
              defaultValue={ALL_ROLES}
              onChange={(value) => list.setFilter('role', value ? [value] : undefined)}
            />
          </FilterBar>
        }
      />
      <InviteUserDialog open={inviting} onClose={() => setInviting(false)} />
      <EditUserDialog user={editing} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={disabling !== null}
        onOpenChange={(open) => {
          if (!open) setDisabling(null)
        }}
        destructive
        title={text.disableTitle}
        description={fill(text.disableBody, { name: disabling?.name ?? '' })}
        confirmLabel={text.disable}
        failedTitle={text.actionFailed}
        onConfirm={async () => {
          if (!disabling) return
          await disableUser(disabling.id)
          await refresh()
          toast.success(fill(text.disabled, { name: disabling.name }))
        }}
      />
    </SettingsPage>
  )
}

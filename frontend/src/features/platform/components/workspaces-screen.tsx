import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Building2Icon, PlusIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import {
  DataTable,
  dataTableColumnHelper,
  FilterBar,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { PageHeader } from '@/components/shared/page-header'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { useListParams } from '@/lib/list-params'
import { type PlatformTenant, platformTenantsQuery } from '../api'
import { SUBSCRIPTION_STATES, tenantApiParams, tenantListSchema } from '../list-schemas'
import { NewWorkspaceDialog } from './new-workspace-dialog'
import { daysLeftText, SubscriptionBadge } from './subscription-badge'
import { TenantStatus } from './tenant-status'

const text = copy.platform
const helper = dataTableColumnHelper<PlatformTenant>()
const STATUS_OPTIONS = [
  { value: 'any', label: text.anyStatus },
  { value: 'active', label: text.statuses.active },
  { value: 'suspended', label: text.statuses.suspended },
  { value: 'archived', label: text.statuses.archived },
]
const STATE_OPTIONS = SUBSCRIPTION_STATES.map((state) => ({ value: state, label: text.states[state] }))

const columns = helper.columns([
  helper.accessor('name', {
    enableHiding: false,
    meta: { label: text.columns.name, className: 'font-medium' },
    cell: (info) => (
      <span className="flex flex-col">
        <span>{info.getValue()}</span>
        <span className="font-mono font-normal text-muted-foreground text-xs">{info.row.original.slug}</span>
      </span>
    ),
  }),
  helper.accessor('status', {
    meta: { label: text.columns.status },
    cell: (info) => <TenantStatus status={info.getValue()} />,
  }),
  helper.accessor((tenant) => tenant.subscription.state, {
    id: 'subscription',
    meta: { label: text.columns.subscription },
    cell: (info) => {
      const subscription = info.row.original.subscription
      return (
        <span className="flex flex-col">
          <SubscriptionBadge state={subscription.state} />
          <span className="text-muted-foreground text-xs">
            {[subscription.plan?.name, daysLeftText(subscription)].filter(Boolean).join(' · ')}
          </span>
        </span>
      )
    },
  }),
  helper.accessor((tenant) => tenant.subscription.ends_at, {
    id: 'ends',
    meta: { label: text.columns.ends, className: 'tabular-nums' },
    cell: (info) => {
      const value = info.getValue()
      return value ? formatInZone(value, info.row.original.timezone, 'd MMM yyyy') : copy.contacts.none
    },
  }),
  helper.accessor('created_at', {
    meta: { label: text.columns.created, className: 'tabular-nums' },
    cell: (info) => {
      const value = info.getValue()
      return value ? formatInZone(value, info.row.original.timezone, 'd MMM yyyy') : copy.contacts.none
    },
  }),
])

/** Every workspace, with its status and subscription; a row opens the workspace (ADR-0025, M6-06). */
export function WorkspacesScreen() {
  const navigate = useNavigate()
  const list = useListParams(tenantListSchema)
  const [creating, setCreating] = useState(false)
  const params = useMemo(() => tenantApiParams(list.params), [list.params])
  const tenants = useQuery(platformTenantsQuery(params))

  return (
    <>
      <PageHeader
        title={text.title}
        description={text.description}
        actions={
          <Button type="button" onClick={() => setCreating(true)}>
            <PlusIcon aria-hidden="true" />
            {text.newWorkspace.open}
          </Button>
        }
      />
      <DataTable
        id="platform-workspaces"
        label={text.tableLabel}
        columns={columns}
        data={tenants.data?.data}
        rowCount={tenants.data?.meta.total}
        state={list.params}
        onStateChange={list.update}
        getRowId={(tenant) => tenant.id}
        onRowOpen={(tenant) =>
          void navigate({ to: '/platform/tenants/$tenantId', params: { tenantId: tenant.id } })
        }
        isFetching={tenants.isFetching && tenants.isPlaceholderData}
        error={tenants.error}
        onRetry={() => void tenants.refetch()}
        emptyState={
          list.activeFilterCount > 0 ? (
            <EmptyState icon={Building2Icon} title={text.noMatchesTitle} description={text.noMatchesBody} />
          ) : (
            <EmptyState icon={Building2Icon} title={text.emptyTitle} description={text.emptyBody} />
          )
        }
        toolbar={
          <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
            <SearchFilter
              label={text.search}
              placeholder={text.searchPlaceholder}
              value={list.params.search}
              onChange={list.setSearch}
            />
            <SelectFilter
              label={text.statusFilter}
              options={STATUS_OPTIONS}
              value={list.params.filters.status}
              defaultValue="any"
              onChange={(value) =>
                list.setFilter('status', value as 'active' | 'suspended' | 'archived' | undefined)
              }
            />
            <MultiSelectFilter
              label={text.subscriptionFilter}
              options={STATE_OPTIONS}
              value={list.params.filters.subscription ?? []}
              onChange={(value) =>
                list.setFilter('subscription', value as (typeof SUBSCRIPTION_STATES)[number][])
              }
            />
          </FilterBar>
        }
      />
      <NewWorkspaceDialog
        open={creating}
        onOpenChange={setCreating}
        onCreated={(tenant) =>
          void navigate({ to: '/platform/tenants/$tenantId', params: { tenantId: tenant.id } })
        }
      />
    </>
  )
}

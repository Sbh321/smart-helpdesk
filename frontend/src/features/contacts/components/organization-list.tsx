import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Building2Icon } from 'lucide-react'
import { useMemo } from 'react'
import {
  DataTable,
  dataTableColumnHelper,
  FilterBar,
  MultiSelectFilter,
  SearchFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { useListParams } from '@/lib/list-params'
import {
  ORGANIZATION_TIERS,
  type Organization,
  organizationListSchema,
  organizationQueries,
} from '../api/organization-queries'

const helper = dataTableColumnHelper<Organization>()
const none = <span className="text-muted-foreground">{copy.contacts.none}</span>
const TIER_OPTIONS = ORGANIZATION_TIERS.map((tier) => ({ value: tier, label: copy.organizations.tier[tier] }))

export function tierLabel(tier: string): string {
  return (ORGANIZATION_TIERS as readonly string[]).includes(tier)
    ? copy.organizations.tier[tier as (typeof ORGANIZATION_TIERS)[number]]
    : tier
}

function organizationColumns(timeZone: string) {
  return helper.columns([
    helper.accessor('name', {
      enableSorting: true,
      enableHiding: false,
      meta: { label: copy.organizations.columns.name, className: 'font-medium' },
    }),
    helper.accessor('domain', {
      meta: { label: copy.organizations.columns.domain },
      cell: (info) => info.getValue() ?? none,
    }),
    helper.accessor('tier', {
      meta: { label: copy.organizations.columns.tier },
      cell: (info) => <Badge variant="outline">{tierLabel(info.getValue())}</Badge>,
    }),
    helper.accessor('contacts_count', {
      meta: { label: copy.organizations.columns.contacts, className: 'tabular-nums' },
    }),
    helper.accessor('tags', {
      meta: { label: copy.organizations.columns.tags },
      cell: (info) =>
        info
          .getValue()
          .map((tag) => tag.name)
          .join(', ') || none,
    }),
    helper.accessor('created_at', {
      enableSorting: true,
      meta: { label: copy.organizations.columns.createdAt, className: 'tabular-nums' },
      cell: (info) => formatInZone(info.getValue(), timeZone, 'd MMM yyyy'),
    }),
  ])
}

/** The organisation list (roadmap M1-15): search, tier filter, sort by name or date added. */
export function OrganizationList({ workspace }: { workspace: string }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const list = useListParams(organizationListSchema)
  const organizations = useQuery({
    ...organizationQueries.list(tenantId, list.apiQuery),
    enabled: tenantId !== '',
  })
  const columns = useMemo(() => organizationColumns(timeZone), [timeZone])

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={Building2Icon}
        title={copy.organizations.list.noMatchesTitle}
        description={copy.organizations.list.noMatchesBody}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState
        icon={Building2Icon}
        title={copy.organizations.list.emptyTitle}
        description={copy.organizations.list.emptyBody}
      />
    )

  return (
    <DataTable
      id="organizations"
      label={copy.organizations.list.label}
      columns={columns}
      data={organizations.data?.data}
      rowCount={organizations.data?.meta.total}
      state={list.params}
      onStateChange={list.update}
      defaultSort={organizationListSchema.defaultSort}
      getRowId={(organization) => organization.id}
      onRowOpen={(organization) =>
        void navigate({
          to: '/$workspace/organizations/$organizationId',
          params: { workspace, organizationId: organization.id },
        })
      }
      isFetching={organizations.isFetching && organizations.isPlaceholderData}
      error={organizations.error}
      onRetry={() => void organizations.refetch()}
      emptyState={emptyState}
      toolbar={
        <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
          <SearchFilter
            label={copy.organizations.list.searchLabel}
            placeholder={copy.organizations.list.searchPlaceholder}
            value={list.params.search}
            onChange={list.setSearch}
          />
          <MultiSelectFilter
            label={copy.organizations.list.tierFilter}
            options={TIER_OPTIONS}
            value={list.params.filters.tier ?? []}
            onChange={(value) => list.setFilter('tier', value)}
          />
        </FilterBar>
      }
    />
  )
}

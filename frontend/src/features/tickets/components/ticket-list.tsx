import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { TicketIcon } from 'lucide-react'
import { useMemo } from 'react'
import {
  DataTable,
  DateRangeFilter,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
  SearchFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { PRIORITY_LEVELS } from '@/components/shared/priority-badge'
import { TICKET_STATUSES } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { useListParams } from '@/lib/list-params'
import { ACTIVE_STATUS, categoryQueries, ticketListSchema, ticketQueries } from '../api/ticket-queries'
import { ticketColumns } from './ticket-columns'

const NO_OPTIONS: FilterOption[] = []
const STATUS_OPTIONS: FilterOption[] = [
  { value: ACTIVE_STATUS, label: copy.tickets.list.activeOption },
  ...TICKET_STATUSES.map((status) => ({ value: status, label: copy.tickets.status[status] })),
]
const PRIORITY_OPTIONS: FilterOption[] = PRIORITY_LEVELS.map((level) => ({
  value: level,
  label: copy.tickets.priority[level],
}))

/**
 * The ticket list (roadmap M1-17) on the DataTable: status (with the `active` alias), priority, category,
 * created date range and search, all in the URL; priority first by default.
 */
export function TicketList({ workspace }: { workspace: string }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const list = useListParams(ticketListSchema)
  const tickets = useQuery({ ...ticketQueries.list(tenantId, list.apiQuery), enabled: tenantId !== '' })
  const categories = useQuery({ ...categoryQueries.all(tenantId), enabled: tenantId !== '' })
  const columns = useMemo(() => ticketColumns(timeZone), [timeZone])
  const categoryOptions = useMemo(
    () => categories.data?.map((category) => ({ value: category.id, label: category.name })) ?? NO_OPTIONS,
    [categories.data],
  )

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={TicketIcon}
        title={copy.tickets.list.noMatchesTitle}
        description={copy.tickets.list.noMatchesBody}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState
        icon={TicketIcon}
        title={copy.tickets.list.emptyTitle}
        description={copy.tickets.list.emptyBody}
      />
    )

  return (
    <DataTable
      id="tickets"
      label={copy.tickets.list.label}
      columns={columns}
      data={tickets.data?.data}
      rowCount={tickets.data?.meta.total}
      state={list.params}
      onStateChange={list.update}
      defaultSort={ticketListSchema.defaultSort}
      getRowId={(ticket) => ticket.id}
      getRowLabel={(ticket) => `#${ticket.number}`}
      onRowOpen={(ticket) =>
        void navigate({ to: '/$workspace/tickets/$ticketId', params: { workspace, ticketId: ticket.id } })
      }
      isFetching={tickets.isFetching && tickets.isPlaceholderData}
      error={tickets.error}
      onRetry={() => void tickets.refetch()}
      emptyState={emptyState}
      toolbar={
        <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
          <SearchFilter
            label={copy.tickets.list.searchLabel}
            placeholder={copy.tickets.list.searchPlaceholder}
            value={list.params.search}
            onChange={list.setSearch}
          />
          <MultiSelectFilter
            label={copy.tickets.list.statusFilter}
            options={STATUS_OPTIONS}
            value={list.params.filters.status ?? []}
            onChange={(value) => list.setFilter('status', value)}
          />
          <MultiSelectFilter
            label={copy.tickets.list.priorityFilter}
            options={PRIORITY_OPTIONS}
            value={list.params.filters.priority ?? []}
            onChange={(value) => list.setFilter('priority', value)}
          />
          <MultiSelectFilter
            label={copy.tickets.list.categoryFilter}
            options={categoryOptions}
            value={list.params.filters.category_id ?? []}
            onChange={(value) => list.setFilter('category_id', value)}
            isLoading={categories.isPending}
          />
          <DateRangeFilter
            label={copy.tickets.list.createdFilter}
            value={list.params.filters.created_between}
            onChange={(value) => list.setFilter('created_between', value)}
          />
        </FilterBar>
      }
    />
  )
}

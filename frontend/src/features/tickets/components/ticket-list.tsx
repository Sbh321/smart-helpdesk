import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { TicketIcon } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import {
  DataTable,
  DateRangeFilter,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { PRIORITY_LEVELS } from '@/components/shared/priority-badge'
import { TICKET_STATUSES } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { useDirectoryNames } from '@/features/agents'
import { organizationQueries, tagQueries } from '@/features/contacts'
import { ExportControls, exportTickets } from '@/features/reports'
import { useCan, useSession } from '@/lib/auth'
import { useListParams } from '@/lib/list-params'
import {
  ACTIVE_STATUS,
  ASSIGNED_TO_ME,
  categoryQueries,
  NO_TEAM,
  SLA_STATES,
  type TicketFilters,
  ticketListSchema,
  ticketQueries,
  UNASSIGNED,
} from '../api/ticket-queries'
import { type BulkMode, TicketBulkButtons, TicketBulkDialogs, type TicketLabel } from './ticket-bulk-actions'
import { TICKET_HIDDEN_COLUMNS, ticketColumns } from './ticket-columns'
import { QUICK_VIEWS, type QuickView, TicketQuickViews } from './ticket-quick-views'
import { TicketListRealtime } from './ticket-realtime'

const NO_OPTIONS: FilterOption[] = []
const STATUS_OPTIONS: FilterOption[] = [
  { value: ACTIVE_STATUS, label: copy.tickets.list.activeOption },
  ...TICKET_STATUSES.map((status) => ({ value: status, label: copy.tickets.status[status] })),
]
const PRIORITY_OPTIONS: FilterOption[] = PRIORITY_LEVELS.map((level) => ({
  value: level,
  label: copy.tickets.priority[level],
}))
const SLA_OPTIONS: FilterOption[] = SLA_STATES.map((state) => ({
  value: state,
  label: copy.tickets.list.slaStates[state],
}))
const DUPLICATE_ANY = 'any'
const DUPLICATE_OPTIONS: FilterOption[] = [
  { value: DUPLICATE_ANY, label: copy.tickets.list.duplicateAny },
  { value: 'true', label: copy.tickets.list.duplicateSuggested },
]
const NO_IDS: string[] = []

/**
 * The ticket list (roadmap M1-17, M2-11) on the DataTable. Every filter, the search and the sort live in
 * the URL: status (with the `active` alias), priority, assignee (`unassigned`, `me`), team (`none`),
 * category, tag, organisation, SLA state, "has a duplicate suggestion" and the created range; the quick
 * views are presets of the same parameters. Rows can be selected across pages for the bulk actions.
 */
export function TicketList({ workspace }: { workspace: string }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const hasAgentProfile = session?.agent_profile != null
  const canUpdate = useCan('tickets.update')
  const canAssign = useCan('tickets.assign')
  const canBulk = canUpdate || canAssign
  const canExport = useCan('reports.export')
  const list = useListParams(ticketListSchema)
  const enabled = tenantId !== ''
  const tickets = useQuery({ ...ticketQueries.list(tenantId, list.apiQuery), enabled })
  const categories = useQuery({ ...categoryQueries.all(tenantId), enabled })
  const organizations = useQuery({ ...organizationQueries.options(tenantId), enabled })
  const tags = useQuery({ ...tagQueries.options(tenantId), enabled })
  const directory = useDirectoryNames()
  const columns = useMemo(() => {
    const agentsById = new Map(directory.agents.map((agent) => [agent.id, agent.user.name]))
    const teamsById = new Map(directory.teams.map((team) => [team.id, team.name]))
    return ticketColumns(timeZone, {
      agentName: (id) => (id ? agentsById.get(id) : undefined),
      teamName: (id) => (id ? teamsById.get(id) : undefined),
    })
  }, [timeZone, directory.agents, directory.teams])
  const categoryOptions = useMemo(
    () => categories.data?.map((category) => ({ value: category.id, label: category.name })) ?? NO_OPTIONS,
    [categories.data],
  )
  const agentOptions = useMemo(
    () => [
      { value: UNASSIGNED, label: copy.tickets.list.unassignedOption },
      ...(hasAgentProfile ? [{ value: ASSIGNED_TO_ME, label: copy.tickets.list.meOption }] : []),
      ...directory.agents.map((agent) => ({ value: agent.id, label: agent.user.name })),
    ],
    [directory.agents, hasAgentProfile],
  )
  const teamOptions = useMemo(
    () => [
      { value: NO_TEAM, label: copy.tickets.list.noTeamOption },
      ...directory.teams.map((team) => ({ value: team.id, label: team.name })),
    ],
    [directory.teams],
  )
  const quickViews = useMemo(
    () => QUICK_VIEWS.filter((view) => view.id !== 'mine' || hasAgentProfile),
    [hasAgentProfile],
  )

  // Selection spans pages; the list remembers number and title of every ticket it has shown, so the
  // bulk results can name a failed ticket that is no longer on the page.
  const [selected, setSelected] = useState<string[]>(NO_IDS)
  const [bulkMode, setBulkMode] = useState<BulkMode>(null)
  const seen = useRef(new Map<string, TicketLabel>())
  for (const ticket of tickets.data?.data ?? []) {
    seen.current.set(ticket.id, { number: ticket.number, title: ticket.title })
  }
  const selection = useMemo(() => ({ ids: selected, onChange: setSelected }), [selected])

  const selectQuickView = (view: QuickView) => {
    const cleared = Object.fromEntries(Object.keys(ticketListSchema.filters).map((key) => [key, undefined]))
    list.update({
      search: undefined,
      sort: view.sort,
      filters: { ...cleared, ...view.filters } as TicketFilters,
    })
  }

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
    <div className="flex flex-col gap-3">
      <TicketListRealtime tenantId={tenantId} />
      <div className="flex flex-wrap items-start justify-between gap-2">
        <TicketQuickViews params={list.params} views={quickViews} onSelect={selectQuickView} />
        {/* Exports the list as filtered, searched and sorted now (roadmap M3-09). */}
        {canExport ? <ExportControls onRequest={(format) => exportTickets(list.apiQuery, format)} /> : null}
      </div>
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
        defaultColumnVisibility={TICKET_HIDDEN_COLUMNS}
        {...(canBulk ? { selection, bulkActions: () => <TicketBulkButtons onOpen={setBulkMode} /> } : {})}
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
              label={copy.tickets.list.assigneeFilter}
              options={agentOptions}
              value={list.params.filters.assignee_id ?? []}
              onChange={(value) => list.setFilter('assignee_id', value)}
              isLoading={directory.isLoading}
            />
            <MultiSelectFilter
              label={copy.tickets.list.teamFilter}
              options={teamOptions}
              value={list.params.filters.team_id ?? []}
              onChange={(value) => list.setFilter('team_id', value)}
              isLoading={directory.isLoading}
            />
            <MultiSelectFilter
              label={copy.tickets.list.categoryFilter}
              options={categoryOptions}
              value={list.params.filters.category_id ?? []}
              onChange={(value) => list.setFilter('category_id', value)}
              isLoading={categories.isPending}
            />
            <MultiSelectFilter
              label={copy.tickets.list.tagFilter}
              options={tags.data ?? NO_OPTIONS}
              value={list.params.filters.tag ?? []}
              onChange={(value) => list.setFilter('tag', value)}
              isLoading={tags.isPending}
            />
            <MultiSelectFilter
              label={copy.tickets.list.organizationFilter}
              options={organizations.data ?? NO_OPTIONS}
              value={list.params.filters.organization_id ?? []}
              onChange={(value) => list.setFilter('organization_id', value)}
              isLoading={organizations.isPending}
            />
            <MultiSelectFilter
              label={copy.tickets.list.slaFilter}
              options={SLA_OPTIONS}
              value={list.params.filters.sla_state ?? []}
              onChange={(value) => list.setFilter('sla_state', value)}
            />
            <SelectFilter
              label={copy.tickets.list.duplicateFilter}
              options={DUPLICATE_OPTIONS}
              value={list.params.filters.has_duplicate_suggestion}
              defaultValue={DUPLICATE_ANY}
              onChange={(value) =>
                list.setFilter('has_duplicate_suggestion', value === 'true' ? 'true' : undefined)
              }
            />
            <DateRangeFilter
              label={copy.tickets.list.createdFilter}
              value={list.params.filters.created_between}
              onChange={(value) => list.setFilter('created_between', value)}
            />
          </FilterBar>
        }
      />
      <TicketBulkDialogs
        mode={bulkMode}
        onModeChange={setBulkMode}
        ids={selected}
        lookup={(id) => seen.current.get(id)}
        agents={directory.agents.map((agent) => ({ id: agent.id, name: agent.user.name }))}
        teams={directory.teams}
        onSelectionChange={setSelected}
      />
    </div>
  )
}

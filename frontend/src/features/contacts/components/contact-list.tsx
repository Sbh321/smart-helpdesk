import { useQuery } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { CopyIcon, UsersIcon } from 'lucide-react'
import { useMemo } from 'react'
import { toast } from 'sonner'
import {
  DataTable,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { useListParams } from '@/lib/list-params'
import {
  ARCHIVED_VALUES,
  type Contact,
  contactListSchema,
  contactQueries,
  NO_ORGANIZATION,
} from '../api/contact-queries'
import { organizationQueries } from '../api/organization-queries'
import { tagQueries } from '../api/tag-queries'
import { contactColumns } from './contact-columns'

const NO_OPTIONS: FilterOption[] = []
const ARCHIVED_OPTIONS: FilterOption[] = ARCHIVED_VALUES.map((value) => ({
  value,
  label: copy.contacts.list.archivedOptions[value],
}))

/**
 * The contact list (roadmap M1-14 acceptance, M1-15): sort, page, search and filters live in the URL
 * through `useListParams`, the rows come from `GET /v1/contacts`.
 */
export function ContactList({ workspace }: { workspace: string }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const list = useListParams(contactListSchema)
  const contacts = useQuery({ ...contactQueries.list(tenantId, list.apiQuery), enabled: tenantId !== '' })
  const organizations = useQuery({ ...organizationQueries.options(tenantId), enabled: tenantId !== '' })
  const tags = useQuery({ ...tagQueries.options(tenantId), enabled: tenantId !== '' })
  const columns = useMemo(() => contactColumns(timeZone), [timeZone])

  const organizationOptions = useMemo(
    () => [
      { value: NO_ORGANIZATION, label: copy.contacts.list.noOrganizationOption },
      ...(organizations.data ?? NO_OPTIONS),
    ],
    [organizations.data],
  )

  const copyEmails = async (rows: Contact[]) => {
    try {
      await navigator.clipboard.writeText(rows.map((contact) => contact.email).join(', '))
      toast.success(fill(copy.contacts.list.copiedEmails, { count: rows.length }))
    } catch {
      toast.error(copy.contacts.list.copyFailed)
    }
  }

  const filtered = list.activeFilterCount > 0
  const emptyState = filtered ? (
    <EmptyState
      icon={UsersIcon}
      title={copy.contacts.list.noMatchesTitle}
      description={copy.contacts.list.noMatchesBody}
      action={
        <Button type="button" variant="outline" onClick={list.clearFilters}>
          {copy.filters.clear}
        </Button>
      }
    />
  ) : (
    <EmptyState
      icon={UsersIcon}
      title={copy.contacts.list.emptyTitle}
      description={copy.contacts.list.emptyBody}
    />
  )

  return (
    <DataTable
      id="contacts"
      label={copy.contacts.list.label}
      columns={columns}
      data={contacts.data?.data}
      rowCount={contacts.data?.meta.total}
      state={list.params}
      onStateChange={list.update}
      defaultSort={contactListSchema.defaultSort}
      onRowOpen={(contact) =>
        void navigate({ to: '/$workspace/contacts/$contactId', params: { workspace, contactId: contact.id } })
      }
      getRowId={(contact) => contact.id}
      getRowLabel={(contact) => contact.name}
      isFetching={contacts.isFetching && contacts.isPlaceholderData}
      error={contacts.error}
      onRetry={() => void contacts.refetch()}
      emptyState={emptyState}
      toolbar={
        <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
          <SearchFilter
            label={copy.contacts.list.searchLabel}
            placeholder={copy.contacts.list.searchPlaceholder}
            value={list.params.search}
            onChange={list.setSearch}
          />
          <MultiSelectFilter
            label={copy.contacts.list.organizationFilter}
            options={organizationOptions}
            value={list.params.filters.organization_id ?? []}
            onChange={(value) => list.setFilter('organization_id', value)}
            isLoading={organizations.isPending}
          />
          <MultiSelectFilter
            label={copy.contacts.list.tagFilter}
            options={tags.data ?? NO_OPTIONS}
            value={list.params.filters.tag ?? []}
            onChange={(value) => list.setFilter('tag', value)}
            isLoading={tags.isPending}
          />
          <SelectFilter
            label={copy.contacts.list.archivedFilter}
            options={ARCHIVED_OPTIONS}
            value={list.params.filters.archived}
            defaultValue="false"
            onChange={(value) => list.setFilter('archived', value as 'true' | 'all' | undefined)}
          />
        </FilterBar>
      }
      bulkActions={({ ids }) => (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() =>
            void copyEmails((contacts.data?.data ?? []).filter((contact) => ids.includes(contact.id)))
          }
        >
          <CopyIcon aria-hidden="true" />
          {copy.contacts.list.copyEmails}
        </Button>
      )}
    />
  )
}

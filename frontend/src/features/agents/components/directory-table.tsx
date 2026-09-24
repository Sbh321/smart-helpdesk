import { InboxIcon, SearchXIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { DataTable, dataTableColumnHelper, SearchFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import type { SortSpec, UseListParamsResult } from '@/lib/list-params'

interface DirectoryRow {
  id: string
  name: string
  details: string
}

const helper = dataTableColumnHelper<DirectoryRow>()

export function DirectoryTable<TSort extends string>({
  id,
  label,
  rows,
  total,
  list,
  defaultSort,
  sortableName,
  onEdit,
  renderName,
}: {
  id: string
  label: string
  rows: DirectoryRow[] | undefined
  total: number | undefined
  list: UseListParamsResult<TSort, Record<string, never>>
  defaultSort: SortSpec<TSort>
  sortableName: boolean
  onEdit?: (id: string) => void
  /** The name cell, for example a link to the record's page (M3-21); plain text by default. */
  renderName?: (row: DirectoryRow) => ReactNode
}) {
  const columns = helper.columns([
    helper.accessor('name', {
      enableSorting: sortableName,
      enableHiding: false,
      meta: { label: copy.settings.name, className: 'font-medium' },
      ...(renderName ? { cell: (info) => renderName(info.row.original) } : {}),
    }),
    helper.accessor('details', {
      meta: { label: copy.settings.descriptionLabel },
    }),
    helper.display({
      id: 'actions',
      meta: { label: copy.settings.actions },
      cell: (info) =>
        onEdit ? (
          <Button variant="outline" size="sm" onClick={() => onEdit(info.row.original.id)}>
            {copy.settings.editNamed.replace('{name}', info.row.original.name)}
          </Button>
        ) : null,
    }),
  ])

  return (
    <DataTable
      id={id}
      label={label}
      columns={columns}
      data={rows}
      rowCount={total}
      state={list.params}
      onStateChange={list.update}
      defaultSort={defaultSort}
      getRowId={(row) => row.id}
      getRowLabel={(row) => row.name}
      // "Nothing here yet" and "nothing matches" are different situations with different ways out (M4-13).
      emptyState={
        list.params.search ? (
          <EmptyState
            icon={SearchXIcon}
            title={fill(copy.settings.noMatches, { search: list.params.search })}
            description={copy.settings.noMatchesBody}
            action={
              <Button variant="outline" onClick={() => list.setSearch(undefined)}>
                {copy.settings.clearSearch}
              </Button>
            }
          />
        ) : (
          <EmptyState icon={InboxIcon} title={copy.settings.empty} />
        )
      }
      toolbar={
        <SearchFilter
          label={fill(copy.settings.searchNamed, { label })}
          value={list.params.search}
          onChange={(value) => list.setSearch(value)}
        />
      }
    />
  )
}

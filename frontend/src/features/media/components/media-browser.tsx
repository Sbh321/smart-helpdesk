import { useQuery } from '@tanstack/react-query'
import { ImagesIcon, LayoutGridIcon, ListIcon } from 'lucide-react'
import { type ReactNode, useCallback, useMemo, useState } from 'react'
import {
  DataTable,
  DataTablePagination,
  FilterBar,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { DropOverlay, useFileDrop } from '@/components/shared/file-drop'
import { Lightbox } from '@/components/shared/lightbox'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { tagQueries } from '@/features/contacts'
import { useSession } from '@/lib/auth'
import { cn } from '@/lib/utils'
import {
  MEDIA_TYPE_GROUPS,
  type MediaFolder,
  type MediaItem,
  type MediaListState,
  type MediaTypeGroup,
  mediaListSchema,
  mediaQueries,
} from '../api/media-queries'
import { mediaLightboxItem } from '../media-files'
import { type MediaRowActions, mediaColumns } from './media-columns'
import { MediaFolderTree } from './media-folder-tree'
import { MediaGrid } from './media-grid'

const text = copy.media
const NO_OPTIONS: never[] = []
const STATE_OPTIONS = [
  { value: 'ready', label: text.stateReady },
  { value: 'trashed', label: text.stateTrashed },
]
const TYPE_OPTIONS = [
  { value: 'all', label: text.typeAll },
  ...MEDIA_TYPE_GROUPS.map((group) => ({ value: group, label: text.typeOptions[group] })),
]
const SORT_OPTIONS = Object.entries(text.sortOptions).map(([value, label]) => ({ value, label }))

type View = 'grid' | 'list'

function storedView(key: string): View {
  try {
    return localStorage.getItem(key) === 'list' ? 'list' : 'grid'
  } catch {
    return 'grid'
  }
}

export interface MediaBrowserProps {
  /** The list state: the page's URL (library) or component state (picker dialog). */
  list: MediaListState
  /** `manage`: cards preview and carry actions; `pick`: cards toggle the selection. */
  mode: 'manage' | 'pick'
  selected: readonly MediaItem[]
  onSelectedChange?: (items: MediaItem[]) => void
  /** One file at a time (a logo): no list view, choosing a card replaces the selection. */
  single?: boolean
  /** The type is fixed (an image picker): the type filter is hidden. */
  lockedType?: MediaTypeGroup
  actions?: MediaRowActions | null
  /** Files that cannot be picked again, for example those already attached. */
  disabledIds?: ReadonlySet<string>
  /** Under the folder tree: the library's folder actions. */
  folderFooter?: (folder: MediaFolder | null) => ReactNode
  /** Files dropped on the browser; without it the browser is no drop target. */
  onFilesDropped?: (files: FileList) => void
  /** The actions for the selection, beside its count. */
  bulkActions?: (items: MediaItem[]) => ReactNode
  /** Where the grid/list choice is remembered for this viewer. */
  viewKey: string
  className?: string
}

/**
 * The media library's body, shared by the library page and the picker dialog (docs/03-architecture/
 * frontend.md §Media): the folder tree, search, type, tag and trash filters, sort, a grid of cards or the
 * table, pagination, selection and the lightbox over the files on the page.
 */
export function MediaBrowser({
  list,
  mode,
  selected,
  onSelectedChange,
  single = false,
  lockedType,
  actions,
  disabledIds,
  folderFooter,
  onFilesDropped,
  bulkActions,
  viewKey,
  className,
}: MediaBrowserProps) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const enabled = tenantId !== ''
  const items = useQuery({ ...mediaQueries.list(tenantId, list.apiQuery), enabled })
  const folders = useQuery({ ...mediaQueries.folders(tenantId), enabled })
  const tags = useQuery({ ...tagQueries.options(tenantId), enabled })
  const [view, setViewState] = useState<View>(() => (single ? 'grid' : storedView(viewKey)))
  const [previewing, setPreviewing] = useState<number | null>(null)
  const drop = useFileDrop((files) => onFilesDropped?.(files), !onFilesDropped)

  const folderId = list.params.filters.folder_id?.[0] ?? null
  const selectedFolder = folders.data?.find((folder) => folder.id === folderId) ?? null
  const rows = items.data?.data
  const previewable = useMemo(() => (rows ?? []).filter((item) => item.state === 'ready'), [rows])
  const lightboxItems = useMemo(
    () => previewable.map((item) => mediaLightboxItem(item, timeZone)),
    [previewable, timeZone],
  )
  const selectedIds = useMemo(() => new Set(selected.map((item) => item.id)), [selected])
  const canSelect = onSelectedChange !== undefined
  const trash = list.params.filters.state === 'trashed'

  const setView = (next: View) => {
    setViewState(next)
    try {
      localStorage.setItem(viewKey, next)
    } catch {
      // Private windows may refuse storage; the choice then lasts for this visit.
    }
  }
  const preview = useCallback(
    (item: MediaItem) => {
      const index = previewable.findIndex((other) => other.id === item.id)
      if (index >= 0) setPreviewing(index)
    },
    [previewable],
  )
  const toggle = (item: MediaItem) => {
    if (!onSelectedChange) return
    if (single) {
      onSelectedChange(selectedIds.has(item.id) ? [] : [item])
      return
    }
    onSelectedChange(
      selectedIds.has(item.id) ? selected.filter((other) => other.id !== item.id) : [...selected, item],
    )
  }
  /** The table reports ids; the items come from the selection so far and the page on screen. */
  const selectIds = (ids: string[]) => {
    const known = new Map([...selected, ...(rows ?? [])].map((item) => [item.id, item]))
    onSelectedChange?.(ids.flatMap((id) => known.get(id) ?? []))
  }

  const columns = useMemo(
    () => mediaColumns(timeZone, mode === 'manage' ? (actions ?? null) : null, preview),
    [timeZone, mode, actions, preview],
  )

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={ImagesIcon}
        title={text.noMatchesTitle}
        description={text.empty}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState icon={ImagesIcon} title={text.emptyTitle} description={text.emptyBody} />
    )

  const toolbar = (
    <div className="flex flex-wrap items-start justify-between gap-2">
      <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters} className="flex-1">
        <SearchFilter
          label={text.search}
          placeholder={text.searchPlaceholder}
          value={list.params.search}
          onChange={list.setSearch}
          shortcut={mode === 'manage'}
        />
        {lockedType ? null : (
          <SelectFilter
            label={text.typeFilter}
            options={TYPE_OPTIONS}
            value={list.params.filters.type}
            defaultValue="all"
            onChange={(value) => list.setFilter('type', value as MediaTypeGroup | undefined)}
          />
        )}
        <MultiSelectFilter
          label={text.tagFilter}
          options={tags.data ?? NO_OPTIONS}
          value={list.params.filters.tag ?? []}
          onChange={(value) => list.setFilter('tag', value)}
          isLoading={tags.isPending}
        />
        {mode === 'manage' ? (
          <SelectFilter
            label={text.stateFilter}
            options={STATE_OPTIONS}
            value={list.params.filters.state}
            defaultValue="ready"
            onChange={(value) => list.setFilter('state', value === 'trashed' ? 'trashed' : undefined)}
          />
        ) : null}
      </FilterBar>
      <div className="flex items-center gap-2">
        <SelectFilter
          label={text.sortLabel}
          options={SORT_OPTIONS}
          value={list.params.sort}
          defaultValue={mediaListSchema.defaultSort}
          onChange={(value) => list.setSort(value as typeof list.params.sort | undefined)}
        />
        {single ? null : (
          // biome-ignore lint/a11y/useSemanticElements: a pair of toggle buttons is a group, not a fieldset of inputs.
          <div
            role="group"
            aria-label={text.viewLabel}
            className="flex rounded-lg border border-field-border bg-surface p-0.5"
          >
            <ViewButton label={text.viewGrid} active={view === 'grid'} onClick={() => setView('grid')}>
              <LayoutGridIcon aria-hidden="true" />
            </ViewButton>
            <ViewButton label={text.viewList} active={view === 'list'} onClick={() => setView('list')}>
              <ListIcon aria-hidden="true" />
            </ViewButton>
          </div>
        )}
      </div>
    </div>
  )

  let body: ReactNode
  if (view === 'list') {
    body = (
      <DataTable
        id={`media-${mode}`}
        label={text.tableLabel}
        columns={columns}
        data={rows}
        rowCount={items.data?.meta.total}
        state={list.params}
        onStateChange={list.update}
        defaultSort={mediaListSchema.defaultSort}
        getRowId={(item) => item.id}
        getRowLabel={(item) => fill(text.selectNamed, { name: item.name })}
        isFetching={items.isFetching && items.isPlaceholderData}
        error={items.error}
        onRetry={() => void items.refetch()}
        emptyState={emptyState}
        {...(canSelect && !trash
          ? {
              selection: { ids: [...selectedIds], onChange: selectIds },
              bulkActions: () => bulkActions?.([...selected]) ?? null,
            }
          : {})}
      />
    )
  } else if (items.isPending) {
    body = (
      <div className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-3">
        {Array.from({ length: 10 }, (_, index) => (
          // biome-ignore lint/suspicious/noArrayIndexKey: placeholders have no identity.
          <Skeleton key={index} className="aspect-4/5 w-full rounded-card" />
        ))}
      </div>
    )
  } else if (items.isError) {
    body = <ErrorState error={items.error} onRetry={() => void items.refetch()} />
  } else if (items.data.data.length === 0) {
    body = emptyState
  } else {
    body = (
      <div className={cn('space-y-3', items.isFetching && items.isPlaceholderData && 'opacity-60')}>
        <MediaGrid
          items={items.data.data}
          mode={mode}
          selectedIds={selectedIds}
          onToggle={canSelect && !trash ? toggle : undefined}
          onPreview={preview}
          actions={mode === 'manage' ? actions : null}
          disabledIds={disabledIds}
        />
        <DataTablePagination
          page={list.params.page}
          perPage={list.params.per_page}
          rowCount={items.data.meta.total}
          onPageChange={list.setPage}
          onPerPageChange={list.setPerPage}
        />
      </div>
    )
  }

  return (
    <div className={cn('grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]', className)}>
      <aside className="space-y-3 self-start rounded-card border border-border bg-surface p-3 shadow-1">
        <h3 className="font-medium text-sm">{text.folders}</h3>
        {folders.isPending ? (
          <Skeleton className="h-20 w-full" />
        ) : folders.isError ? (
          <ErrorState error={folders.error} onRetry={() => void folders.refetch()} />
        ) : (
          <MediaFolderTree
            folders={folders.data}
            selectedId={folderId}
            onSelect={(id) => list.update({ filters: { folder_id: id ? [id] : [] } })}
          />
        )}
        {folders.isSuccess && folderFooter ? folderFooter(selectedFolder) : null}
      </aside>
      <section aria-label={text.contents} className="relative min-w-0 space-y-3" {...drop.dropProps}>
        <DropOverlay
          visible={drop.dragging}
          label={
            selectedFolder ? fill(text.dropToFolder, { folder: selectedFolder.name }) : text.dropToLibrary
          }
        />
        {toolbar}
        {/* The picker counts its selection in its own footer. */}
        {view === 'grid' && mode === 'manage' && canSelect && selected.length > 0 ? (
          <section
            aria-label={text.bulkLabel}
            className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
          >
            <p className="font-medium tabular-nums" aria-live="polite">
              {fill(text.selectedCount, { count: selected.length })}
            </p>
            <div className="flex flex-1 flex-wrap items-center gap-2">{bulkActions?.([...selected])}</div>
            <Button type="button" variant="ghost" size="sm" onClick={() => onSelectedChange?.([])}>
              {text.clearSelection}
            </Button>
          </section>
        ) : null}
        {body}
      </section>
      <Lightbox
        items={lightboxItems}
        index={previewing}
        onIndexChange={setPreviewing}
        onClose={() => setPreviewing(null)}
      />
    </div>
  )
}

function ViewButton({
  label,
  active,
  onClick,
  children,
}: {
  label: string
  active: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <Hint label={label}>
      <Button
        type="button"
        size="icon-xs"
        variant="ghost"
        aria-label={label}
        aria-pressed={active}
        onClick={onClick}
        className={cn('size-7', active ? 'bg-muted text-foreground' : 'text-muted-foreground')}
      >
        {children}
      </Button>
    </Hint>
  )
}

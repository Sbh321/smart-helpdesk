import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderPlusIcon, ImagesIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import {
  DataTable,
  FilterBar,
  MultiSelectFilter,
  SearchFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { tagQueries } from '@/features/contacts'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'
import { useListParams } from '@/lib/list-params'
import {
  deleteFolder,
  type MediaFolder,
  type MediaItem,
  mediaListSchema,
  mediaQueries,
  purgeMedia,
  restoreMedia,
  trashMedia,
  updateMedia,
} from '../api/media-queries'
import { AttachmentUploader } from './attachment-uploader'
import { mediaColumns } from './media-columns'
import { type FolderDialogTarget, MediaFolderDialog } from './media-folder-dialog'
import { MediaFolderTree } from './media-folder-tree'
import { MediaItemDialog } from './media-item-dialog'

const NO_OPTIONS: never[] = []
const STATE_OPTIONS = [
  { value: 'ready', label: copy.media.stateReady },
  { value: 'trashed', label: copy.media.stateTrashed },
]

/** A destructive action waiting for its `ConfirmDialog`. */
type Pending =
  | { kind: 'trash'; item: MediaItem }
  | { kind: 'purge'; item: MediaItem }
  | { kind: 'folder'; folder: MediaFolder }

const CONFIRM = {
  trash: copy.media.confirm.trash,
  purge: copy.media.confirm.purge,
  folder: copy.media.confirm.folder,
}

export function MediaLibraryScreen() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const canView = useCan('media.view')
  const canManage = useCan('media.manage')
  const canUpload = useCan('media.upload')
  const client = useQueryClient()
  const list = useListParams(mediaListSchema)
  const [folderDialog, setFolderDialog] = useState<FolderDialogTarget | null>(null)
  const [editing, setEditing] = useState<MediaItem | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const folderId = list.params.filters.folder_id?.[0] ?? null
  const enabled = canView && tenantId !== ''
  const items = useQuery({ ...mediaQueries.list(tenantId, list.apiQuery), enabled })
  const folders = useQuery({ ...mediaQueries.folders(tenantId), enabled })
  const usage = useQuery({ ...mediaQueries.usage(tenantId), enabled })
  const tags = useQuery({ ...tagQueries.options(tenantId), enabled })
  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.media.all(tenantId) })

  const restore = useMutation({
    mutationFn: (item: MediaItem) => restoreMedia(item.id),
    onSuccess: async () => {
      await refresh()
      toast.success(copy.media.restored)
    },
  })

  const selectedFolder = folders.data?.find((folder) => folder.id === folderId) ?? null
  const columns = useMemo(
    () =>
      mediaColumns(
        timeZone,
        canManage
          ? {
              onEdit: setEditing,
              onTrash: (item) => setPending({ kind: 'trash', item }),
              onPurge: (item) => setPending({ kind: 'purge', item }),
              onRestore: (item) => restore.mutate(item),
              busyId: restore.isPending ? (restore.variables?.id ?? null) : null,
            }
          : null,
      ),
    [timeZone, canManage, restore.mutate, restore.isPending, restore.variables],
  )

  async function uploaded(id: string) {
    try {
      if (folderId) await updateMedia(id, { folder_id: folderId })
      await refresh()
      toast.success(copy.media.uploaded)
    } catch {
      toast.error(copy.media.uploadFailed)
    }
  }

  async function confirmPending() {
    if (!pending) return
    if (pending.kind === 'trash') await trashMedia(pending.item.id)
    if (pending.kind === 'purge') await purgeMedia(pending.item.id)
    if (pending.kind === 'folder') {
      await deleteFolder(pending.folder.id)
      list.update({ filters: { folder_id: pending.folder.parent_id ? [pending.folder.parent_id] : [] } })
    }
    await refresh()
    toast.success(CONFIRM[pending.kind].done)
  }

  if (!canView) return <ForbiddenState />

  const pendingName = pending ? (pending.kind === 'folder' ? pending.folder.name : pending.item.name) : ''
  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={ImagesIcon}
        title={copy.media.noMatchesTitle}
        description={copy.media.empty}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState icon={ImagesIcon} title={copy.media.emptyTitle} description={copy.media.emptyBody} />
    )

  return (
    <section className="space-y-5" aria-labelledby="media-library-heading">
      <div>
        <h2 id="media-library-heading" className="text-xl font-semibold">
          {copy.media.title}
        </h2>
        <p className="text-sm text-muted-foreground">{copy.media.description}</p>
      </div>
      {usage.data ? (
        <div className="space-y-1 rounded-lg border border-border p-3 text-sm">
          <p>
            {fill(copy.media.usedOf, {
              used: formatFileSize(usage.data.used_bytes, copy.media.units),
              quota: formatFileSize(usage.data.quota_bytes, copy.media.units),
            })}
          </p>
          <progress
            className="w-full"
            aria-label={copy.media.used}
            max={usage.data.quota_bytes}
            value={usage.data.used_bytes}
          />
        </div>
      ) : null}
      {canUpload ? <AttachmentUploader onUploaded={(id) => void uploaded(id)} /> : null}
      {restore.error ? <FormErrorBanner title={copy.media.failed} error={restore.error} /> : null}
      <div className="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <aside className="space-y-3 self-start rounded-lg border border-border p-3">
          <h3 className="font-medium">{copy.media.folders}</h3>
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
          {canManage && folders.isSuccess ? (
            <div className="flex flex-wrap gap-2 border-t border-border pt-3">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setFolderDialog({ mode: 'create', parent: selectedFolder })}
              >
                <FolderPlusIcon aria-hidden="true" />
                {copy.media.createFolder}
              </Button>
              {selectedFolder && !selectedFolder.system_key ? (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setFolderDialog({ mode: 'rename', folder: selectedFolder })}
                  >
                    {copy.media.rename}
                  </Button>
                  <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => setPending({ kind: 'folder', folder: selectedFolder })}
                  >
                    {copy.media.deleteFolder}
                  </Button>
                </>
              ) : null}
            </div>
          ) : null}
        </aside>
        <div className="min-w-0">
          <DataTable
            id="media-library"
            label={copy.media.tableLabel}
            columns={columns}
            data={items.data?.data}
            rowCount={items.data?.meta.total}
            state={list.params}
            onStateChange={list.update}
            defaultSort={mediaListSchema.defaultSort}
            getRowId={(item) => item.id}
            isFetching={items.isFetching && items.isPlaceholderData}
            error={items.error}
            onRetry={() => void items.refetch()}
            emptyState={emptyState}
            toolbar={
              <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
                <SearchFilter
                  label={copy.media.search}
                  placeholder={copy.media.searchPlaceholder}
                  value={list.params.search}
                  onChange={list.setSearch}
                />
                <MultiSelectFilter
                  label={copy.media.tagFilter}
                  options={tags.data ?? NO_OPTIONS}
                  value={list.params.filters.tag ?? []}
                  onChange={(value) => list.setFilter('tag', value)}
                  isLoading={tags.isPending}
                />
                <SelectFilter
                  label={copy.media.stateFilter}
                  options={STATE_OPTIONS}
                  value={list.params.filters.state}
                  defaultValue="ready"
                  onChange={(value) => list.setFilter('state', value === 'trashed' ? 'trashed' : undefined)}
                />
              </FilterBar>
            }
          />
        </div>
      </div>
      <MediaFolderDialog target={folderDialog} onClose={() => setFolderDialog(null)} />
      <MediaItemDialog item={editing} folders={folders.data ?? []} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null)
        }}
        destructive
        title={pending ? CONFIRM[pending.kind].title : ''}
        description={pending ? fill(CONFIRM[pending.kind].body, { name: pendingName }) : ''}
        confirmLabel={pending ? CONFIRM[pending.kind].action : ''}
        failedTitle={copy.media.failed}
        onConfirm={confirmPending}
      />
    </section>
  )
}

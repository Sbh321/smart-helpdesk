import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderInputIcon, FolderPlusIcon, HardDriveIcon, Trash2Icon, UploadIcon } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SettingsPage } from '@/components/shared/settings-page'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'
import { useListParams } from '@/lib/list-params'
import { cn } from '@/lib/utils'
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
import { visibleFolders } from '../folder-tree'
import { AttachmentUploader, type AttachmentUploaderHandle } from './attachment-uploader'
import { MediaBrowser } from './media-browser'
import type { MediaRowActions } from './media-columns'
import { type FolderDialogTarget, MediaFolderDialog } from './media-folder-dialog'
import { MediaItemDialog } from './media-item-dialog'

const text = copy.media

/** A destructive action waiting for its `ConfirmDialog`. */
type Pending =
  | { kind: 'trash'; item: MediaItem }
  | { kind: 'purge'; item: MediaItem }
  | { kind: 'folder'; folder: MediaFolder }
  | { kind: 'bulkTrash'; items: MediaItem[] }

/** Runs one request per item; resolves to the number done and the first failure's message. */
async function eachItem(items: MediaItem[], run: (item: MediaItem) => Promise<unknown>) {
  const results = await Promise.allSettled(items.map(run))
  const failed = results.filter((result) => result.status === 'rejected')
  const reason = failed[0]?.status === 'rejected' ? failed[0].reason : null
  return {
    done: results.length - failed.length,
    failed: failed.length,
    reason: reason instanceof Error ? reason.message : text.failed,
  }
}

/**
 * The media library (docs/04-domain/media.md): storage use, an upload field that also takes files
 * dropped anywhere on the browser (into the open folder), and the shared `MediaBrowser` with folders,
 * filters, grid or table, previews in the lightbox, per-item actions and bulk move or trash.
 */
export function MediaLibraryScreen() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const canView = useCan('media.view')
  const canManage = useCan('media.manage')
  const canUpload = useCan('media.upload')
  const client = useQueryClient()
  const list = useListParams(mediaListSchema)
  const uploader = useRef<AttachmentUploaderHandle>(null)
  const [folderDialog, setFolderDialog] = useState<FolderDialogTarget | null>(null)
  const [editing, setEditing] = useState<MediaItem | null>(null)
  const [pending, setPending] = useState<Pending | null>(null)
  const [selected, setSelected] = useState<MediaItem[]>([])
  const folderId = list.params.filters.folder_id?.[0] ?? null
  const enabled = canView && tenantId !== ''
  const folders = useQuery({ ...mediaQueries.folders(tenantId), enabled })
  const usage = useQuery({ ...mediaQueries.usage(tenantId), enabled })
  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.media.all(tenantId) })

  const restore = useMutation({
    mutationFn: (item: MediaItem) => restoreMedia(item.id),
    onSuccess: async () => {
      await refresh()
      toast.success(text.restored)
    },
  })

  const move = useMutation({
    mutationFn: async ({ items, folder }: { items: MediaItem[]; folder: MediaFolder | null }) =>
      eachItem(items, (item) => updateMedia(item.id, { folder_id: folder?.id ?? null })),
    onSuccess: async (result, { items, folder }) => {
      await refresh()
      setSelected([])
      const name = folder?.name ?? text.noFolder
      if (result.failed === 0) toast.success(fill(text.bulkMoved, { count: items.length, folder: name }))
      else toast.error(fill(text.bulkPartly, { ...result, count: items.length }))
    },
  })

  const actions = useMemo<MediaRowActions | null>(
    () =>
      canManage
        ? {
            onEdit: setEditing,
            onTrash: (item) => setPending({ kind: 'trash', item }),
            onPurge: (item) => setPending({ kind: 'purge', item }),
            onRestore: (item) => restore.mutate(item),
            busyId: restore.isPending ? (restore.variables?.id ?? null) : null,
          }
        : null,
    [canManage, restore.mutate, restore.isPending, restore.variables],
  )

  async function uploaded(id: string) {
    try {
      if (folderId) await updateMedia(id, { folder_id: folderId })
      await refresh()
      toast.success(text.uploaded)
    } catch {
      toast.error(text.uploadFailed)
    }
  }

  async function confirmPending() {
    if (!pending) return
    if (pending.kind === 'bulkTrash') {
      const result = await eachItem(pending.items, (item) => trashMedia(item.id))
      await refresh()
      setSelected([])
      if (result.failed === 0) toast.success(fill(text.bulkTrashed, { count: result.done }))
      else toast.error(fill(text.bulkPartly, { ...result, count: pending.items.length }))
      return
    }
    if (pending.kind === 'trash') await trashMedia(pending.item.id)
    if (pending.kind === 'purge') await purgeMedia(pending.item.id)
    if (pending.kind === 'folder') {
      await deleteFolder(pending.folder.id)
      list.update({ filters: { folder_id: pending.folder.parent_id ? [pending.folder.parent_id] : [] } })
    }
    await refresh()
    toast.success(text.confirm[pending.kind].done)
  }

  if (!canView) return <ForbiddenState />

  const confirm =
    pending === null
      ? null
      : pending.kind === 'bulkTrash'
        ? {
            title: fill(text.bulkTrash.title, { count: pending.items.length }),
            description: text.bulkTrash.body,
            action: text.bulkTrash.action,
          }
        : {
            title: text.confirm[pending.kind].title,
            description: fill(text.confirm[pending.kind].body, {
              name: pending.kind === 'folder' ? pending.folder.name : pending.item.name,
            }),
            action: text.confirm[pending.kind].action,
          }

  const folderFooter = (folder: MediaFolder | null) =>
    canManage ? (
      <div className="flex flex-wrap gap-2 border-border border-t pt-3">
        <Button
          size="sm"
          variant="outline"
          onClick={() => setFolderDialog({ mode: 'create', parent: folder })}
        >
          <FolderPlusIcon aria-hidden="true" />
          {text.createFolder}
        </Button>
        {folder && !folder.system_key ? (
          <>
            <Button size="sm" variant="outline" onClick={() => setFolderDialog({ mode: 'rename', folder })}>
              {text.rename}
            </Button>
            <Button size="sm" variant="destructive" onClick={() => setPending({ kind: 'folder', folder })}>
              {text.deleteFolder}
            </Button>
          </>
        ) : null}
      </div>
    ) : null

  const bulkActions = (items: MediaItem[]) =>
    canManage ? (
      <>
        <DropdownMenu>
          <DropdownMenuTrigger
            render={<Button type="button" variant="outline" size="sm" disabled={move.isPending} />}
          >
            <FolderInputIcon aria-hidden="true" />
            {text.moveTo}
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="max-h-72 min-w-52 overflow-y-auto">
            <DropdownMenuItem onClick={() => move.mutate({ items, folder: null })}>
              {text.noFolder}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            {visibleFolders(folders.data ?? [], new Set(folders.data?.map((folder) => folder.id))).map(
              ({ folder, level }) => (
                <DropdownMenuItem
                  key={folder.id}
                  onClick={() => move.mutate({ items, folder })}
                  style={{ paddingInlineStart: `${0.375 + (level - 1) * 0.875}rem` }}
                >
                  {folder.name}
                </DropdownMenuItem>
              ),
            )}
          </DropdownMenuContent>
        </DropdownMenu>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setPending({ kind: 'bulkTrash', items })}
        >
          <Trash2Icon aria-hidden="true" />
          {text.bulkTrash.action}
        </Button>
      </>
    ) : null

  return (
    <SettingsPage
      title={text.title}
      description={text.description}
      actions={
        canUpload ? (
          <Button type="button" onClick={() => uploader.current?.choose()}>
            <UploadIcon aria-hidden="true" />
            {text.upload}
          </Button>
        ) : null
      }
    >
      {usage.data ? <StorageMeter used={usage.data.used_bytes} quota={usage.data.quota_bytes} /> : null}
      {canUpload ? <AttachmentUploader handle={uploader} onUploaded={(id) => void uploaded(id)} /> : null}
      {restore.error ? <FormErrorBanner title={text.failed} error={restore.error} /> : null}
      <MediaBrowser
        list={list}
        mode="manage"
        selected={selected}
        onSelectedChange={canManage ? setSelected : undefined}
        actions={actions}
        folderFooter={folderFooter}
        onFilesDropped={canUpload ? (files) => uploader.current?.add(files) : undefined}
        bulkActions={bulkActions}
        viewKey="sh.media.view"
      />
      <MediaFolderDialog target={folderDialog} onClose={() => setFolderDialog(null)} />
      <MediaItemDialog item={editing} folders={folders.data ?? []} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null)
        }}
        destructive
        title={confirm?.title ?? ''}
        description={confirm?.description ?? ''}
        confirmLabel={confirm?.action ?? ''}
        failedTitle={text.failed}
        onConfirm={confirmPending}
      />
    </SettingsPage>
  )
}

function StorageMeter({ used, quota }: { used: number; quota: number }) {
  const percent = quota > 0 ? Math.min(100, Math.round((used / quota) * 100)) : 0
  return (
    <section
      aria-label={text.storage}
      className="flex items-center gap-4 rounded-card border border-border bg-surface p-4 shadow-1"
    >
      <span
        aria-hidden="true"
        className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
      >
        <HardDriveIcon className="size-5" />
      </span>
      <div className="min-w-0 flex-1 space-y-2">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
          <p className="font-medium">
            {fill(text.usedOf, {
              used: formatFileSize(used, text.units),
              quota: formatFileSize(quota, text.units),
            })}
          </p>
          <p className="text-muted-foreground text-xs tabular-nums">{fill(text.usedPercent, { percent })}</p>
        </div>
        <progress
          aria-label={text.used}
          max={quota}
          value={used}
          className={cn(
            'block h-2 w-full overflow-hidden rounded-full',
            percent >= 90 ? 'accent-destructive' : 'accent-primary',
          )}
        />
      </div>
    </section>
  )
}

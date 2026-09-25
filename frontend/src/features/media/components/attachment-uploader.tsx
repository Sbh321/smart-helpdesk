import { FolderOpenIcon, ImagesIcon, RotateCcwIcon, UploadCloudIcon, XIcon } from 'lucide-react'
import { type Ref, useEffect, useId, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { useFileDrop } from '@/components/shared/file-drop'
import { Lightbox, type LightboxItem } from '@/components/shared/lightbox'
import { Button } from '@/components/ui/button'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'
import { cn } from '@/lib/utils'
import type { MediaItem, UploadPurpose } from '../api/media-queries'
import { rejectionReason, UploadAborted, type UploadProgressState, uploadFile } from '../api/upload-file'
import {
  localFileLightboxItem,
  MEDIA_KIND_ICONS,
  mediaKind,
  mediaLightboxItem,
  mediaThumbUrl,
  mediaTypeLabel,
} from '../media-files'
import { MediaPickerDialog } from './media-picker-dialog'

const text = copy.media

type Upload = {
  id: string
  source: 'upload'
  file: File
  /** `URL.createObjectURL(file)`: the tile's thumbnail and the lightbox's source; revoked on removal. */
  objectUrl: string
  progress: number
  state: 'uploading' | 'ready' | 'error'
  mediaId?: string
  error?: string
  /** A file the pre-checks refused can only be removed. */
  retryable: boolean
}
type Picked = { id: string; source: 'library'; item: MediaItem; state: 'ready'; mediaId: string }
type Entry = Upload | Picked

/** What the surrounding form needs to know: which Media items are ready, and whether to wait. */
export interface AttachmentUploaderState {
  mediaIds: string[]
  uploading: boolean
}

/** For a screen with a larger drop target or its own "Upload" button (the library). */
export interface AttachmentUploaderHandle {
  add: (files: FileList) => void
  /** Opens the device's file chooser. */
  choose: () => void
}

const RECEIPT_ACCEPT = 'image/png,image/jpeg,image/gif,image/webp,application/pdf'

/** A receipt is a photo, a screenshot or a PDF. */
function isReceiptFile(file: File): boolean {
  return /\.(png|jpe?g|gif|webp|pdf)$/i.test(file.name)
}

const entryName = (entry: Entry) => (entry.source === 'upload' ? entry.file.name : entry.item.name)

/**
 * The one upload field (docs/03-architecture/frontend.md §Media): a drop zone that takes files dragged
 * onto it, "Choose files" from this device and, with `library`, "Choose from library" (the whole
 * library in a dialog). Each file becomes a tile with a thumbnail, progress, retry and remove; a tile
 * opens the lightbox over all of them. Uploads run intent → PUT → complete with cancel and retry.
 *
 * Two ways to use it. A form reads `onChange` (the ready ids and "still uploading") and resets the field
 * by changing its `key`; picked library files then join the tiles. A screen that acts at once (the
 * Ticket's attachments tab, the library) passes `onUploaded` and `onPicked` instead. Unmounting aborts
 * what is in flight and silences late callbacks, so an upload finishing after a reset never reaches the
 * next comment.
 */
export function AttachmentUploader({
  onChange,
  onUploaded,
  onPicked,
  library = false,
  maxFiles = 50,
  excludeIds,
  handle,
  purpose = 'attachment',
}: {
  onChange?: (state: AttachmentUploaderState) => void
  /** Fired once per uploaded file when it is ready. */
  onUploaded?: (mediaId: string) => void
  /** Library files go straight to the caller instead of joining the tiles. */
  onPicked?: (items: MediaItem[]) => void
  /** Offers "Choose from library" (with `media.view`). */
  library?: boolean
  maxFiles?: number
  /** Files already attached, which the library dialog shows but does not offer again. */
  excludeIds?: readonly string[]
  handle?: Ref<AttachmentUploaderHandle>
  /** Where the upload goes; a `receipt` is one image or PDF in the Billing folder (ADR-0025 §4). */
  purpose?: UploadPurpose
}) {
  const inputId = useId()
  const input = useRef<HTMLInputElement>(null)
  const canUpload = useCan('media.upload')
  const canPick = useCan('media.view') && library
  const timeZone = useSession().session?.tenant.timezone ?? 'UTC'
  const [entries, setEntries] = useState<Entry[]>([])
  const [announcement, setAnnouncement] = useState('')
  const [picking, setPicking] = useState(false)
  const [previewing, setPreviewing] = useState<number | null>(null)
  const live = useRef(true)
  const controllers = useRef(new Map<string, AbortController>())
  const progressStates = useRef(new Map<string, UploadProgressState>())
  const objectUrls = useRef(new Set<string>())
  const callbacks = useRef({ onChange, onUploaded })
  useEffect(() => {
    callbacks.current = { onChange, onUploaded }
  })

  useEffect(() => {
    live.current = true
    const running = controllers.current
    const urls = objectUrls.current
    return () => {
      live.current = false
      for (const controller of running.values()) controller.abort()
      running.clear()
      for (const url of urls) URL.revokeObjectURL(url)
      urls.clear()
    }
  }, [])

  useEffect(() => {
    callbacks.current.onChange?.({
      mediaIds: entries.flatMap((entry) => (entry.mediaId ? [entry.mediaId] : [])),
      uploading: entries.some((entry) => entry.state === 'uploading'),
    })
  }, [entries])

  const lightboxItems = useMemo<LightboxItem[]>(
    () =>
      entries.map((entry) =>
        entry.source === 'upload'
          ? localFileLightboxItem(entry.id, entry.file, entry.objectUrl)
          : mediaLightboxItem(entry.item, timeZone),
      ),
    [entries, timeZone],
  )

  function patch(id: string, change: Partial<Upload>) {
    setEntries((current) =>
      current.map((entry) =>
        entry.id === id && entry.source === 'upload' ? { ...entry, ...change } : entry,
      ),
    )
  }

  async function start(id: string, file: File) {
    const controller = new AbortController()
    controllers.current.set(id, controller)
    const state = progressStates.current.get(id) ?? {}
    progressStates.current.set(id, state)
    patch(id, { state: 'uploading', error: undefined, progress: state.stored ? 100 : 0 })
    try {
      const mediaId = await uploadFile(file, {
        signal: controller.signal,
        state,
        purpose,
        onProgress: (progress) => {
          if (live.current && !controller.signal.aborted) patch(id, { progress })
        },
      })
      if (!live.current || controller.signal.aborted) return
      patch(id, { state: 'ready', progress: 100, mediaId })
      setAnnouncement(fill(text.announceReady, { name: file.name }))
      callbacks.current.onUploaded?.(mediaId)
    } catch (error) {
      if (!live.current || error instanceof UploadAborted || controller.signal.aborted) return
      const message = error instanceof Error ? error.message : text.uploadFailed
      patch(id, { state: 'error', error: message })
      setAnnouncement(fill(text.announceFailed, { name: file.name, reason: message }))
    } finally {
      if (controllers.current.get(id) === controller) controllers.current.delete(id)
    }
  }

  function add(files: FileList | null) {
    if (!files || !canUpload) return
    const available = Math.max(0, maxFiles - entries.length)
    const added = Array.from(files)
      .slice(0, available)
      .map((file): Upload => {
        const objectUrl = URL.createObjectURL(file)
        objectUrls.current.add(objectUrl)
        const rejected =
          rejectionReason(file) ?? (purpose === 'receipt' && !isReceiptFile(file) ? text.receiptOnly : null)
        const base = { id: crypto.randomUUID(), source: 'upload' as const, file, objectUrl, progress: 0 }
        return rejected
          ? { ...base, state: 'error', error: rejected, retryable: false }
          : { ...base, state: 'uploading', retryable: true }
      })
    setEntries((current) => [...current, ...added])
    if (files.length > available) setAnnouncement(fill(text.announceLimit, { max: maxFiles }))
    for (const entry of added) if (entry.state === 'uploading') void start(entry.id, entry.file)
  }

  function pick(items: MediaItem[]) {
    if (onPicked) {
      onPicked(items)
      return
    }
    setEntries((current) => {
      const have = new Set(current.map((entry) => entry.mediaId))
      const fresh = items
        .filter((item) => !have.has(item.id))
        .slice(0, Math.max(0, maxFiles - current.length))
        .map(
          (item): Picked => ({
            id: `library-${item.id}`,
            source: 'library',
            item,
            state: 'ready',
            mediaId: item.id,
          }),
        )
      return [...current, ...fresh]
    })
    setAnnouncement(fill(text.announcePicked, { count: items.length }))
  }

  /** Cancel while uploading, remove afterwards: either way the file will not be part of the submit. */
  function remove(entry: Entry) {
    if (entry.source === 'upload') {
      controllers.current.get(entry.id)?.abort()
      controllers.current.delete(entry.id)
      progressStates.current.delete(entry.id)
      URL.revokeObjectURL(entry.objectUrl)
      objectUrls.current.delete(entry.objectUrl)
    }
    setEntries((current) => current.filter((other) => other.id !== entry.id))
    setPreviewing(null)
    setAnnouncement(
      fill(entry.state === 'uploading' ? text.announceCancelled : text.announceRemoved, {
        name: entryName(entry),
      }),
    )
  }

  useImperativeHandle(handle, () => ({ add, choose: () => input.current?.click() }))
  const drop = useFileDrop(add, !canUpload)
  const attachedIds = new Set([...(excludeIds ?? []), ...entries.flatMap((entry) => entry.mediaId ?? [])])

  if (!canUpload && !canPick) {
    return <p className="text-muted-foreground text-sm">{text.uploadPermission}</p>
  }

  return (
    // Laid out by its own width (container queries): it sits in wide forms and in the narrow side panel.
    <div className="@container space-y-3">
      <div
        {...drop.dropProps}
        className={cn(
          'flex flex-col items-center gap-3 rounded-card border border-field-border border-dashed bg-surface p-4 text-center transition-colors @xl:flex-row @xl:text-left',
          drop.dragging && 'border-primary bg-primary/5',
        )}
      >
        <span
          aria-hidden="true"
          className={cn(
            'flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground',
            drop.dragging && 'bg-primary/15 text-primary',
          )}
        >
          <UploadCloudIcon className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-medium text-sm">
            {drop.dragging ? text.dropActive : canUpload ? text.dropTitle : text.chooseFromLibrary}
          </p>
          {canUpload ? (
            <p id={`${inputId}-hint`} className="text-muted-foreground text-xs">
              {purpose === 'receipt' ? text.receiptOnly : text.uploadHint}
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap justify-center gap-2">
          {canUpload ? (
            <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
              <FolderOpenIcon aria-hidden="true" />
              {text.chooseFiles}
            </Button>
          ) : null}
          {canPick ? (
            <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
              <ImagesIcon aria-hidden="true" />
              {text.chooseFromLibrary}
            </Button>
          ) : null}
        </div>
        {canUpload ? (
          // The visible "Choose files" button is the keyboard route; the input stays for its label and
          // for assistive technology that works with file inputs directly.
          <input
            ref={input}
            id={inputId}
            type="file"
            multiple={maxFiles > 1}
            accept={purpose === 'receipt' ? RECEIPT_ACCEPT : undefined}
            tabIndex={-1}
            aria-label={text.addFiles}
            aria-describedby={`${inputId}-hint`}
            className="sr-only"
            onChange={(event) => {
              add(event.target.files)
              event.target.value = ''
            }}
          />
        ) : null}
      </div>
      {/* Progress ticks are not announced; only a change of state is. */}
      <p role="status" className="sr-only">
        {announcement}
      </p>
      {entries.length > 0 ? (
        <ul className="grid gap-2 @2xl:grid-cols-2" aria-label={text.uploadList}>
          {entries.map((entry, index) => (
            <EntryTile
              key={entry.id}
              entry={entry}
              onPreview={() => setPreviewing(index)}
              onRetry={entry.source === 'upload' ? () => void start(entry.id, entry.file) : undefined}
              onRemove={() => remove(entry)}
            />
          ))}
        </ul>
      ) : null}
      {canPick ? (
        <MediaPickerDialog open={picking} onOpenChange={setPicking} onPick={pick} disabledIds={attachedIds} />
      ) : null}
      <Lightbox
        items={lightboxItems}
        index={previewing}
        onIndexChange={setPreviewing}
        onClose={() => setPreviewing(null)}
      />
    </div>
  )
}

function EntryTile({
  entry,
  onPreview,
  onRetry,
  onRemove,
}: {
  entry: Entry
  onPreview: () => void
  onRetry?: () => void
  onRemove: () => void
}) {
  const name = entryName(entry)
  const mime = entry.source === 'upload' ? entry.file.type : entry.item.mime_type
  const size = entry.source === 'upload' ? entry.file.size : entry.item.size_bytes
  const Icon = MEDIA_KIND_ICONS[mediaKind(mime)]
  const thumb =
    entry.source === 'upload'
      ? mediaKind(mime) === 'image' && entry.state !== 'error'
        ? entry.objectUrl
        : undefined
      : mediaThumbUrl(entry.item)
  const removeLabel = fill(entry.state === 'uploading' ? text.cancelUpload : text.removeFile, { name })

  return (
    <li
      className={cn(
        'flex items-center gap-3 rounded-lg border border-border bg-surface p-2 text-sm',
        entry.state === 'error' && 'border-destructive/40',
      )}
    >
      <Hint label={fill(text.previewNamed, { name })}>
        <button
          type="button"
          aria-label={fill(text.previewNamed, { name })}
          onClick={onPreview}
          className="flex size-11 shrink-0 cursor-zoom-in items-center justify-center overflow-hidden rounded-control bg-muted text-muted-foreground outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          {thumb ? (
            <img src={thumb} alt="" className="size-full object-cover" />
          ) : (
            <Icon aria-hidden="true" className="size-5" />
          )}
        </button>
      </Hint>
      <div className="min-w-0 flex-1 space-y-1">
        <p className="truncate font-medium">{name}</p>
        {entry.state === 'uploading' && entry.source === 'upload' ? (
          <div className="flex items-center gap-2">
            <progress
              className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full accent-primary"
              max={100}
              value={entry.progress}
              aria-label={fill(text.progressLabel, { name })}
            />
            <span className="w-9 text-right text-muted-foreground text-xs tabular-nums" aria-hidden="true">
              {entry.progress}%
            </span>
          </div>
        ) : entry.state === 'error' ? (
          <p className="text-destructive text-xs">{entry.error}</p>
        ) : (
          <p className="truncate text-muted-foreground text-xs">
            <span className="text-success">{entry.source === 'library' ? text.fromLibrary : text.ready}</span>
            {' · '}
            {mediaTypeLabel(mime, name)} · {formatFileSize(size, text.units)}
          </p>
        )}
      </div>
      {entry.state === 'error' && entry.source === 'upload' && entry.retryable && onRetry ? (
        <Hint label={fill(text.retryFile, { name })}>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={fill(text.retryFile, { name })}
            onClick={onRetry}
          >
            <RotateCcwIcon aria-hidden="true" />
          </Button>
        </Hint>
      ) : null}
      <Hint label={removeLabel}>
        <Button type="button" variant="ghost" size="icon-sm" aria-label={removeLabel} onClick={onRemove}>
          <XIcon aria-hidden="true" />
        </Button>
      </Hint>
    </li>
  )
}

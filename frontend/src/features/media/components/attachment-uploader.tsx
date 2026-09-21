import { XIcon } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { rejectionReason, UploadAborted, type UploadProgressState, uploadFile } from '../api/upload-file'

type Entry = {
  id: string
  file: File
  progress: number
  state: 'uploading' | 'ready' | 'error'
  mediaId?: string
  error?: string
  /** A file the pre-checks refused can only be removed. */
  retryable: boolean
}

/** What the surrounding form needs to know: which Media items are ready, and whether to wait. */
export interface AttachmentUploaderState {
  mediaIds: string[]
  uploading: boolean
}

/**
 * File input with per-file progress, cancel, remove and retry. The uploader owns its entries; the form
 * around it reads `onChange` (ready ids + "still uploading") and resets it by changing its `key`.
 * Unmounting aborts what is in flight and silences late callbacks, so an upload that finishes after a
 * reset never reaches the next comment.
 */
export function AttachmentUploader({
  onChange,
  onUploaded,
  maxFiles = 50,
}: {
  onChange?: (state: AttachmentUploaderState) => void
  /** Fired once per file when it is ready (the attachments tab links it straight away). */
  onUploaded?: (mediaId: string) => void
  maxFiles?: number
}) {
  const inputId = useId()
  const [entries, setEntries] = useState<Entry[]>([])
  const [announcement, setAnnouncement] = useState('')
  const live = useRef(true)
  const controllers = useRef(new Map<string, AbortController>())
  const progressStates = useRef(new Map<string, UploadProgressState>())
  const callbacks = useRef({ onChange, onUploaded })
  useEffect(() => {
    callbacks.current = { onChange, onUploaded }
  })

  useEffect(() => {
    live.current = true
    const running = controllers.current
    return () => {
      live.current = false
      for (const controller of running.values()) controller.abort()
      running.clear()
    }
  }, [])

  useEffect(() => {
    callbacks.current.onChange?.({
      mediaIds: entries.flatMap((entry) => (entry.mediaId ? [entry.mediaId] : [])),
      uploading: entries.some((entry) => entry.state === 'uploading'),
    })
  }, [entries])

  function patch(id: string, change: Partial<Entry>) {
    setEntries((current) => current.map((entry) => (entry.id === id ? { ...entry, ...change } : entry)))
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
        onProgress: (progress) => {
          if (live.current && !controller.signal.aborted) patch(id, { progress })
        },
      })
      if (!live.current || controller.signal.aborted) return
      patch(id, { state: 'ready', progress: 100, mediaId })
      setAnnouncement(fill(copy.media.announceReady, { name: file.name }))
      callbacks.current.onUploaded?.(mediaId)
    } catch (error) {
      if (!live.current || error instanceof UploadAborted || controller.signal.aborted) return
      const message = error instanceof Error ? error.message : copy.media.uploadFailed
      patch(id, { state: 'error', error: message })
      setAnnouncement(fill(copy.media.announceFailed, { name: file.name, reason: message }))
    } finally {
      if (controllers.current.get(id) === controller) controllers.current.delete(id)
    }
  }

  function add(files: FileList | null) {
    if (!files) return
    const available = Math.max(0, maxFiles - entries.length)
    const added = Array.from(files)
      .slice(0, available)
      .map((file): Entry => {
        const rejected = rejectionReason(file)
        return rejected
          ? { id: crypto.randomUUID(), file, progress: 0, state: 'error', error: rejected, retryable: false }
          : { id: crypto.randomUUID(), file, progress: 0, state: 'uploading', retryable: true }
      })
    setEntries((current) => [...current, ...added])
    if (files.length > available) setAnnouncement(fill(copy.media.announceLimit, { max: maxFiles }))
    for (const entry of added) if (entry.state === 'uploading') void start(entry.id, entry.file)
  }

  /** Cancel while uploading, remove afterwards: either way the file will not be part of the submit. */
  function remove(entry: Entry) {
    controllers.current.get(entry.id)?.abort()
    controllers.current.delete(entry.id)
    progressStates.current.delete(entry.id)
    setEntries((current) => current.filter((other) => other.id !== entry.id))
    setAnnouncement(
      fill(entry.state === 'uploading' ? copy.media.announceCancelled : copy.media.announceRemoved, {
        name: entry.file.name,
      }),
    )
  }

  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium" htmlFor={inputId}>
        {copy.media.addFiles}
      </label>
      <input
        id={inputId}
        type="file"
        multiple
        aria-describedby={`${inputId}-hint`}
        onChange={(event) => {
          add(event.target.files)
          event.target.value = ''
        }}
        className="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-border file:bg-muted file:px-3 file:py-1.5 file:text-sm"
      />
      <p id={`${inputId}-hint`} className="text-xs text-muted-foreground">
        {copy.media.uploadHint}
      </p>
      {/* Progress ticks are not announced; only a change of state is. */}
      <p role="status" className="sr-only">
        {announcement}
      </p>
      {entries.length > 0 ? (
        <ul className="space-y-2" aria-label={copy.media.uploadList}>
          {entries.map((entry) => (
            <li key={entry.id} className="flex flex-wrap items-center gap-2 text-sm">
              <span className="min-w-0 flex-1 truncate">{entry.file.name}</span>
              {entry.state === 'uploading' ? (
                <>
                  <progress
                    className="h-2 w-24"
                    max={100}
                    value={entry.progress}
                    aria-label={fill(copy.media.progressLabel, { name: entry.file.name })}
                  />
                  <span className="w-10 text-right tabular-nums" aria-hidden="true">
                    {entry.progress}%
                  </span>
                </>
              ) : entry.state === 'ready' ? (
                <span className="text-success">{copy.media.ready}</span>
              ) : (
                <span className="text-destructive">{entry.error}</span>
              )}
              {entry.state === 'error' && entry.retryable ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  aria-label={fill(copy.media.retryFile, { name: entry.file.name })}
                  onClick={() => void start(entry.id, entry.file)}
                >
                  {copy.media.retry}
                </Button>
              ) : null}
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={fill(
                  entry.state === 'uploading' ? copy.media.cancelUpload : copy.media.removeFile,
                  { name: entry.file.name },
                )}
                onClick={() => remove(entry)}
              >
                <XIcon aria-hidden="true" />
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

import { ImagesIcon, UploadIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useFileDrop } from '@/components/shared/file-drop'
import { Lightbox } from '@/components/shared/lightbox'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import {
  isImageFile,
  MediaPickerDialog,
  mediaUrls,
  rejectionReason,
  UploadAborted,
  uploadFile,
} from '@/features/media'
import { isApiError } from '@/lib/api/errors'
import { useCan } from '@/lib/auth'
import { cn } from '@/lib/utils'

const text = copy.workspaceSettings.brandingForm

/**
 * One workspace logo: preview (opens the lightbox), upload a single image by dropping it on the preview
 * or choosing it (media intent → PUT → complete with `purpose: 'branding'`), choose an image already in
 * the library, and remove. The value is the Media item id; the form saves it.
 */
export function LogoField({
  id,
  label,
  description,
  theme,
  mediaId,
  onChange,
  onBusyChange,
  errors,
}: {
  id: string
  label: string
  description: string
  /** The surface the preview sits on: the theme this logo is meant for. */
  theme: 'light' | 'dark'
  mediaId: string | null
  onChange: (mediaId: string | null) => void
  onBusyChange: (busy: boolean) => void
  errors: readonly string[]
}) {
  const input = useRef<HTMLInputElement>(null)
  const controller = useRef<AbortController | null>(null)
  const [progress, setProgress] = useState<number | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const [picking, setPicking] = useState(false)
  const [previewing, setPreviewing] = useState(false)
  const canPick = useCan('media.view')
  const lower = label.toLowerCase()

  useEffect(() => () => controller.current?.abort(), [])

  const upload = async (file: File) => {
    const refused = isImageFile(file) ? rejectionReason(file) : text.imagesOnly
    if (refused) {
      setFailure(refused)
      return
    }
    const abort = new AbortController()
    controller.current = abort
    setFailure(null)
    setProgress(0)
    onBusyChange(true)
    try {
      onChange(
        await uploadFile(file, {
          onProgress: setProgress,
          signal: abort.signal,
          state: {},
          purpose: 'branding',
        }),
      )
    } catch (error) {
      if (error instanceof UploadAborted) return
      const detail = isApiError(error)
        ? (error.detail ?? error.title)
        : error instanceof Error
          ? error.message
          : ''
      setFailure(detail ? `${text.uploadFailed} ${detail}` : text.uploadFailed)
    } finally {
      if (!abort.signal.aborted) {
        setProgress(null)
        onBusyChange(false)
      }
    }
  }

  const messages = [...errors, ...(failure ? [failure] : [])]
  const busy = progress !== null
  const drop = useFileDrop((files) => {
    const file = files[0]
    if (file) void upload(file)
  }, busy)
  const urls = mediaId ? mediaUrls(mediaId) : null

  return (
    <fieldset className="flex flex-col gap-2" aria-describedby={`${id}-description`}>
      <legend className="text-sm font-medium">{label}</legend>
      <p id={`${id}-description`} className="text-sm text-muted-foreground">
        {description}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        <div
          {...drop.dropProps}
          data-theme={theme}
          className={cn(
            'relative flex h-16 w-48 items-center justify-center overflow-hidden rounded-card border border-field-border border-dashed bg-background p-2 text-foreground transition-colors',
            drop.dragging && 'border-primary bg-primary/10',
          )}
        >
          {drop.dragging ? (
            <span className="text-center font-medium text-primary text-xs">{text.dropLogo}</span>
          ) : urls ? (
            <button
              type="button"
              onClick={() => setPreviewing(true)}
              aria-label={fill(text.previewLogo, { label: lower })}
              className="flex size-full cursor-zoom-in items-center justify-center rounded-control outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              <img
                src={urls.open}
                alt={fill(text.logoAlt, { label: lower })}
                className="max-h-full max-w-full object-contain"
              />
            </button>
          ) : (
            <span className="text-center text-muted-foreground text-xs">{text.noLogo}</span>
          )}
        </div>
        <input
          ref={input}
          id={id}
          type="file"
          accept="image/png,image/jpeg,image/gif,image/webp"
          className="sr-only"
          tabIndex={-1}
          aria-hidden="true"
          aria-label={fill(text.fileInput, { label })}
          onChange={(event) => {
            const file = event.target.files?.[0]
            event.target.value = ''
            if (file) void upload(file)
          }}
        />
        <Button type="button" variant="outline" disabled={busy} onClick={() => input.current?.click()}>
          <UploadIcon aria-hidden="true" />
          {fill(mediaId ? text.replace : text.upload, { label: lower })}
        </Button>
        {canPick ? (
          <Button type="button" variant="outline" disabled={busy} onClick={() => setPicking(true)}>
            <ImagesIcon aria-hidden="true" />
            {text.chooseLogo}
          </Button>
        ) : null}
        {mediaId ? (
          <Button type="button" variant="ghost" disabled={busy} onClick={() => onChange(null)}>
            {fill(text.remove, { label: lower })}
          </Button>
        ) : null}
      </div>
      <p role="status" className="text-sm text-muted-foreground">
        {busy ? fill(text.uploading, { progress }) : ''}
      </p>
      {messages.map((message) => (
        <p key={message} role="alert" className="text-sm text-destructive">
          {message}
        </p>
      ))}
      {canPick ? (
        <MediaPickerDialog
          open={picking}
          onOpenChange={setPicking}
          single
          type="image"
          onPick={(items) => {
            const item = items[0]
            if (!item) return
            setFailure(null)
            onChange(item.id)
          }}
        />
      ) : null}
      <Lightbox
        items={
          urls && mediaId
            ? [
                {
                  id: mediaId,
                  name: label,
                  kind: 'image',
                  summary: description,
                  sourceUrl: urls.open,
                  openUrl: urls.open,
                  downloadUrl: urls.download,
                },
              ]
            : []
        }
        index={previewing && urls ? 0 : null}
        onIndexChange={() => undefined}
        onClose={() => setPreviewing(false)}
      />
    </fieldset>
  )
}

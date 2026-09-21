import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { isImageFile, rejectionReason, UploadAborted, uploadFile } from '@/features/media'
import { apiUrl } from '@/lib/api/client'
import { isApiError } from '@/lib/api/errors'

const text = copy.workspaceSettings.brandingForm

/**
 * One workspace logo: preview, upload (a single image, through the media intent → PUT → complete flow
 * with `purpose: 'branding'`) and remove. The value is the Media item id; the form saves it.
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

  return (
    <fieldset className="flex flex-col gap-2" aria-describedby={`${id}-description`}>
      <legend className="text-sm font-medium">{label}</legend>
      <p id={`${id}-description`} className="text-sm text-muted-foreground">
        {description}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        <div
          data-theme={theme}
          className="flex h-14 w-40 items-center justify-center rounded-md border border-border bg-background p-2 text-foreground"
        >
          {mediaId ? (
            <img
              src={apiUrl(`/v1/media/${mediaId}/download`)}
              alt={fill(text.logoAlt, { label: lower })}
              className="max-h-full max-w-full object-contain"
            />
          ) : (
            <span className="text-xs text-muted-foreground">{text.noLogo}</span>
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
          {fill(mediaId ? text.replace : text.upload, { label: lower })}
        </Button>
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
    </fieldset>
  )
}

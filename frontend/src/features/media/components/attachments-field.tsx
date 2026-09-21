import { XIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { copy, fill } from '@/copy/en'
import { useCan } from '@/lib/auth'
import type { MediaItem } from '../api/media-queries'
import { AttachmentUploader, type AttachmentUploaderState } from './attachment-uploader'
import { MediaPicker } from './media-picker'

const NOTHING: AttachmentUploaderState = { mediaIds: [], uploading: false }

/**
 * The attachment part of a form (comment composer, ticket create): upload new files, pick stored ones,
 * remove either before submitting. The form reads `onChange` — the ids to send and whether uploads are
 * still running, in which case it keeps its submit button disabled — and resets the field by `key`.
 */
export function AttachmentsField({
  onChange,
  maxFiles = 10,
}: {
  onChange: (state: AttachmentUploaderState) => void
  maxFiles?: number
}) {
  const canUpload = useCan('media.upload')
  const canPick = useCan('media.view')
  const [uploads, setUploads] = useState(NOTHING)
  const [picked, setPicked] = useState<Pick<MediaItem, 'id' | 'name'>[]>([])
  const report = useRef(onChange)
  useEffect(() => {
    report.current = onChange
  })

  useEffect(() => {
    const ids = new Set([...uploads.mediaIds, ...picked.map((item) => item.id)])
    report.current({ mediaIds: [...ids], uploading: uploads.uploading })
  }, [uploads, picked])

  if (!canUpload && !canPick)
    return <p className="text-sm text-muted-foreground">{copy.media.uploadPermission}</p>

  return (
    <div className="space-y-3">
      {canUpload ? (
        <AttachmentUploader maxFiles={maxFiles} onChange={setUploads} />
      ) : (
        <p className="text-sm text-muted-foreground">{copy.media.uploadPermission}</p>
      )}
      {canPick ? (
        <MediaPicker
          onPick={(item) =>
            setPicked((current) =>
              current.some((other) => other.id === item.id)
                ? current
                : [...current, { id: item.id, name: item.name }],
            )
          }
        />
      ) : null}
      {picked.length > 0 ? (
        <ul className="space-y-1" aria-label={copy.media.pickedList}>
          {picked.map((item) => (
            <li key={item.id} className="flex items-center gap-2 text-sm">
              <span className="min-w-0 flex-1 truncate">{item.name}</span>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={fill(copy.media.removeFile, { name: item.name })}
                onClick={() => setPicked((current) => current.filter((other) => other.id !== item.id))}
              >
                <XIcon aria-hidden="true" />
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
      {uploads.uploading ? (
        <p className="text-xs text-muted-foreground">{copy.media.waitForUploads}</p>
      ) : null}
    </div>
  )
}

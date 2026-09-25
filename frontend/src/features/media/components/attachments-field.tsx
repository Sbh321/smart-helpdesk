import { useState } from 'react'
import { copy } from '@/copy/en'
import { AttachmentUploader, type AttachmentUploaderState } from './attachment-uploader'

/**
 * The attachment part of a form (comment composer, ticket create): files dropped or chosen from this
 * device, and files chosen from the library, all as tiles that can be previewed and removed before
 * submitting. The form reads `onChange` (the ids to send, and whether uploads are still running, in
 * which case it keeps its submit button disabled) and resets the field by `key`.
 */
export function AttachmentsField({
  onChange,
  maxFiles = 10,
}: {
  onChange: (state: AttachmentUploaderState) => void
  maxFiles?: number
}) {
  const [uploading, setUploading] = useState(false)
  return (
    <div className="space-y-2">
      <AttachmentUploader
        library
        maxFiles={maxFiles}
        onChange={(state) => {
          setUploading(state.uploading)
          onChange(state)
        }}
      />
      {uploading ? <p className="text-muted-foreground text-xs">{copy.media.waitForUploads}</p> : null}
    </div>
  )
}

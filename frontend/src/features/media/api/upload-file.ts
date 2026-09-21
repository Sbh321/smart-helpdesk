import { copy, fill } from '@/copy/en'
import { completeUpload, type UploadIntent, type UploadPurpose, uploadIntent } from './media-queries'

const MAX_BYTES = 25 * 1024 * 1024

const MIME_BY_EXTENSION: Record<string, string> = {
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  gif: 'image/gif',
  webp: 'image/webp',
  pdf: 'application/pdf',
  txt: 'text/plain',
  csv: 'text/csv',
  log: 'text/plain',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  zip: 'application/zip',
}

function mediaMime(file: File): string | null {
  const extension = file.name.split('.').at(-1)?.toLowerCase() ?? ''
  return MIME_BY_EXTENSION[extension] ?? null
}

/** True for the image types the API stores (workspace logos accept nothing else). */
export function isImageFile(file: File): boolean {
  return mediaMime(file)?.startsWith('image/') ?? false
}

/** The checks the API repeats, made before a byte is sent. A message means "do not upload this file". */
export function rejectionReason(file: File): string | null {
  if (!mediaMime(file)) return copy.media.typeNotAllowed
  if (file.size < 1 || file.size > MAX_BYTES) return copy.media.sizeNotAllowed
  return null
}

/** Thrown when the caller's `AbortSignal` fired; never shown as a failure. */
export class UploadAborted extends Error {
  constructor() {
    super('aborted')
    this.name = 'UploadAborted'
  }
}

/**
 * What one file's upload has achieved so far. The caller keeps it between attempts, so a retry after
 * "the PUT succeeded but `complete` failed" completes the same intent instead of orphaning it.
 */
export interface UploadProgressState {
  intent?: UploadIntent
  stored?: boolean
}

function putFile(
  url: string,
  headers: Record<string, string>,
  file: File,
  mime: string,
  progress: (value: number) => void,
  signal: AbortSignal,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()
    const abort = () => request.abort()
    request.open('PUT', url)
    for (const [key, value] of Object.entries(headers)) request.setRequestHeader(key, value)
    if (!Object.keys(headers).some((key) => key.toLowerCase() === 'content-type')) {
      request.setRequestHeader('Content-Type', mime)
    }
    request.upload.onprogress = (event) => {
      if (event.lengthComputable) progress(Math.round((event.loaded / event.total) * 100))
    }
    request.onloadend = () => signal.removeEventListener('abort', abort)
    request.onload = () =>
      request.status >= 200 && request.status < 300
        ? resolve()
        : reject(new Error(fill(copy.media.uploadHttpError, { status: request.status })))
    request.onerror = () => reject(new Error(copy.media.uploadNetworkError))
    request.onabort = () => reject(new UploadAborted())
    signal.addEventListener('abort', abort)
    request.send(file)
  })
}

function stopIfAborted(signal: AbortSignal): void {
  if (signal.aborted) throw new UploadAborted()
}

/** Intent → PUT to storage → complete. Resolves to the Media item id. */
export async function uploadFile(
  file: File,
  options: {
    onProgress: (value: number) => void
    signal: AbortSignal
    state: UploadProgressState
    /** Defaults to an attachment; `branding` is a workspace logo. */
    purpose?: UploadPurpose
  },
): Promise<string> {
  const { onProgress, signal, state, purpose } = options
  const mime = mediaMime(file)
  const rejected = rejectionReason(file)
  if (!mime || rejected) throw new Error(rejected ?? copy.media.typeNotAllowed)
  stopIfAborted(signal)
  if (!state.stored || !state.intent) {
    // A failed PUT may have used up the presigned URL, so only a stored file keeps its intent.
    state.stored = false
    state.intent = await uploadIntent(file.name, file.size, mime, purpose)
    stopIfAborted(signal)
    await putFile(state.intent.url, state.intent.headers, file, mime, onProgress, signal)
    state.stored = true
  }
  onProgress(100)
  stopIfAborted(signal)
  const item = await completeUpload(state.intent.media_id)
  stopIfAborted(signal)
  return item.id
}

import type { LucideIcon } from 'lucide-react'
import {
  FileArchiveIcon,
  FileIcon,
  FileSpreadsheetIcon,
  FileTextIcon,
  ImageIcon,
  PresentationIcon,
} from 'lucide-react'
import type { LightboxItem, LightboxKind } from '@/components/shared/lightbox'
import { copy, fill } from '@/copy/en'
import { apiUrl } from '@/lib/api/client'
import { formatInZone } from '@/lib/datetime/format'
import { formatFileSize } from '@/lib/format/file-size'
import type { MediaItem } from './api/media-queries'

/** What a file is, for its icon, its type label and how the lightbox shows it. */
export type MediaKind =
  | 'image'
  | 'pdf'
  | 'text'
  | 'spreadsheet'
  | 'document'
  | 'presentation'
  | 'archive'
  | 'other'

const OFFICE = 'application/vnd.openxmlformats-officedocument.'

export function mediaKind(mime: string): MediaKind {
  if (mime.startsWith('image/')) return 'image'
  if (mime === 'application/pdf') return 'pdf'
  if (mime === 'text/csv' || mime === `${OFFICE}spreadsheetml.sheet`) return 'spreadsheet'
  if (mime.startsWith('text/')) return 'text'
  if (mime === `${OFFICE}wordprocessingml.document`) return 'document'
  if (mime === `${OFFICE}presentationml.presentation`) return 'presentation'
  if (mime === 'application/zip') return 'archive'
  return 'other'
}

export const MEDIA_KIND_ICONS: Record<MediaKind, LucideIcon> = {
  image: ImageIcon,
  pdf: FileTextIcon,
  text: FileTextIcon,
  spreadsheet: FileSpreadsheetIcon,
  document: FileTextIcon,
  presentation: PresentationIcon,
  archive: FileArchiveIcon,
  other: FileIcon,
}

/**
 * The types the API serves inline at `GET /media/{id}/open` (MediaStorage::VIEWABLE), and so the ones
 * the lightbox can show: raster images, PDF, and text (CSV and logs as text).
 */
const VIEWABLE: Record<string, LightboxKind> = {
  'image/png': 'image',
  'image/jpeg': 'image',
  'image/gif': 'image',
  'image/webp': 'image',
  'application/pdf': 'pdf',
  'text/plain': 'text',
  'text/csv': 'text',
}

export function lightboxKind(mime: string): LightboxKind {
  return VIEWABLE[mime] ?? 'file'
}

/** "PNG image", "PDF document", "Spreadsheet" … */
export function mediaTypeLabel(mime: string, name: string): string {
  const kind = mediaKind(mime)
  if (kind === 'image') {
    const extension = name.split('.').at(-1)?.toUpperCase() ?? ''
    return fill(copy.media.types.image, { format: extension === 'JPEG' ? 'JPG' : extension || 'Image' })
  }
  if (mime === 'text/csv') return copy.media.types.csv
  return copy.media.types[kind]
}

/** Every URL of a stored file. All go through the API, which checks access and redirects to storage. */
export function mediaUrls(id: string) {
  return {
    download: apiUrl(`/v1/media/${id}/download`),
    open: apiUrl(`/v1/media/${id}/open`),
    thumb: apiUrl(`/v1/media/${id}/variants/thumb`),
    preview: apiUrl(`/v1/media/${id}/variants/preview`),
  }
}

type Previewable = Pick<
  MediaItem,
  'id' | 'name' | 'mime_type' | 'size_bytes' | 'width' | 'height' | 'variants'
> &
  Partial<Pick<MediaItem, 'created_at' | 'tags' | 'used_in_tickets' | 'used_in_count' | 'state'>>

/** "PNG image · 2.4 MB · 1920 × 1080" */
export function mediaSummary(
  item: Pick<Previewable, 'name' | 'mime_type' | 'size_bytes' | 'width' | 'height'>,
) {
  return [
    mediaTypeLabel(item.mime_type, item.name),
    formatFileSize(item.size_bytes, copy.media.units),
    item.width && item.height
      ? fill(copy.media.dimensions, { width: item.width, height: item.height })
      : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

/** The thumbnail URL when the API made one (images below the pixel limit). */
export function mediaThumbUrl(item: Pick<Previewable, 'id' | 'variants'>): string | undefined {
  return item.variants.thumb ? mediaUrls(item.id).thumb : undefined
}

/** A stored file as the lightbox shows it, with its details panel. */
export function mediaLightboxItem(item: Previewable, timeZone?: string): LightboxItem {
  const urls = mediaUrls(item.id)
  const kind = lightboxKind(item.mime_type)
  const labels = copy.media.detailLabels
  const details: LightboxItem['details'] = [
    { label: labels.name, value: item.name },
    { label: labels.type, value: mediaTypeLabel(item.mime_type, item.name) },
    { label: labels.size, value: formatFileSize(item.size_bytes, copy.media.units) },
  ]
  if (item.width && item.height) {
    details.push({
      label: labels.dimensions,
      value: fill(copy.media.dimensions, { width: item.width, height: item.height }),
    })
  }
  if (item.created_at && timeZone) {
    details.push({ label: labels.added, value: formatInZone(item.created_at, timeZone) })
  }
  if (item.tags && item.tags.length > 0) {
    details.push({ label: labels.tags, value: item.tags.map((tag) => tag.name).join(', ') })
  }
  if (item.used_in_count !== undefined) {
    details.push({
      label: labels.usedIn,
      value:
        item.used_in_count === 0
          ? copy.media.notUsed
          : item.used_in_tickets && item.used_in_tickets.length > 0
            ? item.used_in_tickets
                .map((ticket) => fill(copy.tickets.number, { number: ticket.number }))
                .join(', ')
            : fill(copy.media.usedCount, { count: item.used_in_count }),
    })
  }
  return {
    id: item.id,
    name: item.name,
    kind,
    summary: mediaSummary(item),
    icon: MEDIA_KIND_ICONS[mediaKind(item.mime_type)],
    thumbUrl: mediaThumbUrl(item),
    previewUrl: item.variants.preview ? urls.preview : undefined,
    sourceUrl: kind === 'file' ? undefined : urls.open,
    openUrl: urls.open,
    downloadUrl: urls.download,
    details,
  }
}

/**
 * A file on this device, before or during its upload, as the lightbox shows it. `objectUrl` is the
 * caller's `URL.createObjectURL(file)`, which the caller also revokes.
 */
export function localFileLightboxItem(id: string, file: File, objectUrl: string): LightboxItem {
  const mime = file.type || 'application/octet-stream'
  const kind = lightboxKind(mime)
  const labels = copy.media.detailLabels
  return {
    id,
    name: file.name,
    kind,
    summary: [mediaTypeLabel(mime, file.name), formatFileSize(file.size, copy.media.units)].join(' · '),
    icon: MEDIA_KIND_ICONS[mediaKind(mime)],
    thumbUrl: kind === 'image' ? objectUrl : undefined,
    sourceUrl: kind === 'file' ? undefined : objectUrl,
    openUrl: kind === 'file' ? undefined : objectUrl,
    downloadUrl: objectUrl,
    details: [
      { label: labels.name, value: file.name },
      { label: labels.type, value: mediaTypeLabel(mime, file.name) },
      { label: labels.size, value: formatFileSize(file.size, copy.media.units) },
      { label: labels.source, value: copy.media.thisDevice },
    ],
  }
}

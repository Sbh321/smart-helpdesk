import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { z } from 'zod'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components, operations } from '@/lib/api/schema'
import {
  type ApiListQuery,
  choiceFilter,
  defineListSchema,
  multiFilter,
  type UseListParamsResult,
} from '@/lib/list-params'

export type MediaItem = components['schemas']['MediaItemResource']
export type MediaFolder = components['schemas']['MediaFolderResource']
export type MediaUsage = components['schemas']['MediaUsageResource']
export type UploadIntent = components['schemas']['UploadIntentResource']
export type MediaListPage = operations['media.index']['responses'][200]['content']['application/json']

type MediaIndex = operations['media.index']
type MediaListQuery = NonNullable<MediaIndex['parameters']['query']>

/** The API's type groups (`filter[type]`): images, PDF and Office documents, text and CSV, ZIP. */
export const MEDIA_TYPE_GROUPS = ['image', 'document', 'text', 'archive'] as const
export type MediaTypeGroup = (typeof MEDIA_TYPE_GROUPS)[number]

export const mediaListSchema = defineListSchema({
  sortFields: ['name', 'size_bytes', 'created_at'] as const,
  defaultSort: '-created_at',
  filters: {
    folder_id: multiFilter(z.uuid()),
    state: choiceFilter(['trashed']),
    type: choiceFilter(MEDIA_TYPE_GROUPS),
    tag: multiFilter(z.string().trim().min(1).max(40)),
  },
})

/** The media list's state, from the URL (`useListParams`) or from a dialog (`useLocalListParams`). */
export type MediaListState = UseListParamsResult<
  'name' | 'size_bytes' | 'created_at',
  (typeof mediaListSchema)['filters']
>

export const mediaQueries = {
  list: (tenantId: string, query: ApiListQuery) =>
    queryOptions({
      queryKey: queryKeys.media.list(tenantId, query),
      queryFn: () => unwrapBody(api().GET('/media', { params: { query: query as MediaListQuery } })),
      placeholderData: keepPreviousData,
    }),
  folders: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.media.folders(tenantId),
      queryFn: () => unwrap(api().GET('/media/folders')),
    }),
  usage: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.media.usage(tenantId),
      queryFn: () => unwrap(api().GET('/media/usage')),
    }),
  ticketAttachments: (tenantId: string, ticketId: string) =>
    queryOptions({
      queryKey: queryKeys.tickets.attachments(tenantId, ticketId),
      queryFn: () =>
        unwrap(api().GET('/tickets/{ticket}/attachments', { params: { path: { ticket: ticketId } } })),
    }),
}

/**
 * `branding` puts the file into the Branding folder and needs `settings.manage` (workspace logos);
 * `receipt` into the Billing folder, images and PDF only, with `billing.manage` (ADR-0025 §4).
 */
export type UploadPurpose = 'attachment' | 'branding' | 'receipt'

export function uploadIntent(
  filename: string,
  size: number,
  mime: string,
  purpose?: UploadPurpose,
): Promise<UploadIntent> {
  return unwrap(
    api().POST('/media/intent', { body: { filename, size, mime, ...(purpose ? { purpose } : {}) } }),
  )
}

export function completeUpload(mediaId: string): Promise<MediaItem> {
  return unwrap(api().POST('/media/{media}/complete', { params: { path: { media: mediaId } } }))
}

export function linkTicketMedia(ticketId: string, mediaIds: string[]): Promise<MediaItem[]> {
  return unwrap(
    api().POST('/tickets/{ticket}/attachments', {
      params: { path: { ticket: ticketId } },
      body: { media_ids: mediaIds },
    }),
  )
}

export function unlinkTicketMedia(ticketId: string, mediaId: string): Promise<void> {
  return unwrapBody(
    api().DELETE('/tickets/{ticket}/attachments/{media}', {
      params: { path: { ticket: ticketId, media: mediaId } },
    }),
  ) as Promise<void>
}

export function createFolder(name: string, parentId: string | null): Promise<MediaFolder> {
  return unwrap(api().POST('/media/folders', { body: { name, parent_id: parentId } }))
}

export function updateFolder(
  id: string,
  input: { name?: string; parent_id?: string | null },
): Promise<MediaFolder> {
  return unwrap(api().PATCH('/media/folders/{folder}', { params: { path: { folder: id } }, body: input }))
}

export function deleteFolder(id: string): Promise<void> {
  return unwrapBody(
    api().DELETE('/media/folders/{folder}', { params: { path: { folder: id } } }),
  ) as Promise<void>
}

export function updateMedia(
  id: string,
  input: { name?: string; folder_id?: string | null; tags?: string[] },
): Promise<MediaItem> {
  return unwrap(api().PATCH('/media/{media}', { params: { path: { media: id } }, body: input }))
}

export function trashMedia(id: string): Promise<MediaItem> {
  return unwrap(api().POST('/media/{media}/trash', { params: { path: { media: id } } }))
}

export function restoreMedia(id: string): Promise<MediaItem> {
  return unwrap(api().POST('/media/{media}/restore', { params: { path: { media: id } } }))
}

export function purgeMedia(id: string): Promise<void> {
  return unwrapBody(api().DELETE('/media/{media}', { params: { path: { media: id } } })) as Promise<void>
}

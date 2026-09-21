import { HttpResponse, http } from 'msw'
import { db, type MediaItemResource, NOW, nextId } from './data'
import { apiUrl, problem, TEST_API_ORIGIN } from './handlers'
import { paginate, sortRows, validateListQuery, validationFailed } from './list'

const notFound = () => problem(404, 'not_found', { title: 'Not found' })

/** Where the presigned PUT goes: a host of its own, as the storage endpoint is in production. */
export const TEST_STORAGE_ORIGIN = 'https://files.test'

const PIXEL_PNG =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

function find(id: unknown): MediaItemResource | undefined {
  return db.media.find((row) => row.id === id)
}

export const mediaHandlers = [
  http.get(apiUrl('/media'), ({ request }) => {
    const url = new URL(request.url)
    const invalid = validateListQuery(url, {
      sortable: ['name', 'size_bytes', 'created_at'],
      filters: ['folder_id', 'state', 'tag'],
      defaultSort: '-created_at',
    })
    if (invalid) return invalid
    const search = url.searchParams.get('search')?.trim().toLowerCase()
    const folders = url.searchParams.get('filter[folder_id]')?.split(',')
    const trashed = url.searchParams.get('filter[state]') === 'trashed'
    const tags = url.searchParams.get('filter[tag]')?.split(',')
    const rows = db.media.filter(
      (item) =>
        (item.state === 'trashed') === trashed &&
        (trashed || item.state === 'ready') &&
        (!search || item.name.toLowerCase().includes(search)) &&
        (!folders || (item.folder_id !== null && folders.includes(item.folder_id))) &&
        (!tags || item.tags.some((tag) => tags.includes(tag.slug))),
    )
    return HttpResponse.json(paginate(url, sortRows(rows, url.searchParams.get('sort') ?? '-created_at')))
  }),
  http.get(apiUrl('/media/usage'), () =>
    HttpResponse.json({
      data: {
        used_bytes: db.media.reduce((total, item) => total + item.size_bytes, 0),
        quota_bytes: 1024 ** 3,
        pending_bytes: 0,
      },
    }),
  ),
  http.get(apiUrl('/media/folders'), () => HttpResponse.json({ data: db.mediaFolders })),
  http.post(apiUrl('/media/folders'), async ({ request }) => {
    const body = (await request.json()) as { name: string; parent_id: string | null }
    if (!body.name?.trim()) return validationFailed({ name: ['The name field is required.'] })
    if (db.mediaFolders.some((row) => row.parent_id === body.parent_id && row.name === body.name.trim()))
      return validationFailed({ name: ['The name has already been taken.'] })
    const folder = {
      id: nextId(13),
      parent_id: body.parent_id,
      name: body.name.trim(),
      system_key: null,
      created_at: NOW,
      updated_at: NOW,
    }
    db.mediaFolders.push(folder)
    return HttpResponse.json({ data: folder }, { status: 201 })
  }),
  http.patch(apiUrl('/media/folders/{folder}'), async ({ params, request }) => {
    const folder = db.mediaFolders.find((row) => row.id === params.folder)
    if (!folder) return notFound()
    const body = (await request.json()) as { name?: string }
    if (body.name !== undefined && !body.name.trim())
      return validationFailed({ name: ['The name field is required.'] })
    if (body.name) folder.name = body.name.trim()
    return HttpResponse.json({ data: folder })
  }),
  http.delete(apiUrl('/media/folders/{folder}'), ({ params }) => {
    const folder = db.mediaFolders.find((row) => row.id === params.folder)
    if (!folder) return notFound()
    const used =
      db.media.some((item) => item.folder_id === folder.id) ||
      db.mediaFolders.some((row) => row.parent_id === folder.id)
    if (used) return problem(409, 'in_use', { detail: 'Move or delete the contents of this folder first.' })
    db.mediaFolders = db.mediaFolders.filter((row) => row.id !== folder.id)
    return new HttpResponse(null, { status: 204 })
  }),
  http.post(apiUrl('/media/intent'), async ({ request }) => {
    const body = (await request.json()) as { filename: string; size: number; mime: string }
    const item: MediaItemResource = {
      id: nextId(14),
      folder_id: null,
      name: body.filename,
      mime_type: body.mime,
      size_bytes: body.size,
      width: null,
      height: null,
      checksum_sha256: null,
      variants: { thumb: null, preview: null },
      variants_skipped: null,
      source: 'upload',
      state: 'pending',
      uploaded_by_user_id: null,
      used_in_count: 0,
      used_in_tickets: [],
      tags: [],
      trashed_at: null,
      completed_at: null,
      created_at: NOW,
    }
    db.media.push(item)
    return HttpResponse.json(
      { data: { media_id: item.id, url: `${TEST_STORAGE_ORIGIN}/upload/${item.id}`, headers: {} } },
      { status: 201 },
    )
  }),
  http.put(`${TEST_STORAGE_ORIGIN}/upload/:media`, () => new HttpResponse(null, { status: 200 })),
  http.post(apiUrl('/media/{media}/complete'), ({ params }) => {
    const item = find(params.media)
    if (!item) return notFound()
    item.state = 'ready'
    item.completed_at = NOW
    return HttpResponse.json({ data: item })
  }),
  http.patch(apiUrl('/media/{media}'), async ({ params, request }) => {
    const item = find(params.media)
    if (!item) return notFound()
    const body = (await request.json()) as { name?: string; folder_id?: string | null; tags?: string[] }
    if (body.name !== undefined && !body.name.trim())
      return validationFailed({ name: ['The name field is required.'] })
    if (body.name) item.name = body.name.trim()
    if (body.folder_id !== undefined) item.folder_id = body.folder_id
    if (body.tags)
      item.tags = body.tags.map((name) => ({ name, slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-') }))
    return HttpResponse.json({ data: item })
  }),
  http.post(apiUrl('/media/{media}/trash'), ({ params }) => {
    const item = find(params.media)
    if (!item) return notFound()
    if (item.used_in_count > 0)
      return problem(409, 'in_use', { detail: 'This media item is attached to a ticket.' })
    item.state = 'trashed'
    item.trashed_at = NOW
    return HttpResponse.json({ data: item })
  }),
  http.post(apiUrl('/media/{media}/restore'), ({ params }) => {
    const item = find(params.media)
    if (!item) return notFound()
    item.state = 'ready'
    item.trashed_at = null
    return HttpResponse.json({ data: item })
  }),
  http.delete(apiUrl('/media/{media}'), ({ params }) => {
    const item = find(params.media)
    if (!item) return notFound()
    db.media = db.media.filter((row) => row.id !== item.id)
    return new HttpResponse(null, { status: 204 })
  }),
  /** Thumbnails and downloads are plain `<img>`/`<a>` requests to the API host. */
  http.get(
    `${TEST_API_ORIGIN}/v1/media/:media/variants/:name`,
    () => new HttpResponse(null, { status: 404 }),
  ),
  /** The API redirects a download to a signed URL; here it answers with a 1×1 PNG so `<img>` loads. */
  http.get(`${TEST_API_ORIGIN}/v1/media/:media/download`, () => {
    const bytes = Uint8Array.from(atob(PIXEL_PNG), (char) => char.charCodeAt(0))
    return new HttpResponse(bytes, { headers: { 'Content-Type': 'image/png' } })
  }),
]

import { HttpResponse, http } from 'msw'
import type { components } from '@/lib/api/schema'
import { apiUrl, problem } from './handlers'

type ReportExport = components['schemas']['ReportExportResource']

/** Media id of every ready export file in the fixtures. */
export const EXPORT_MEDIA_ID = '01990000-0000-7000-8000-00000000e001'

/** Export requests the SPA sent, in order, with their bodies: tests assert on them. */
export const exportRequests: { path: string; body: Record<string, unknown> }[] = []
const exports = new Map<string, { item: ReportExport; polls: number }>()

export function resetExports(): void {
  exportRequests.length = 0
  exports.clear()
}

function queue(reportKey: string, body: Record<string, unknown>): Response {
  const format = body.format === 'xlsx' ? 'xlsx' : 'csv'
  const id = `01990000-0000-7000-8000-${String(exports.size + 1).padStart(12, '0')}`
  const item: ReportExport = {
    id,
    report_key: reportKey,
    format,
    state: 'queued',
    row_count: null,
    error: null,
    media_id: null,
    file_name: null,
    size_bytes: null,
    download_url: null,
    created_at: '2026-09-21T09:00:00Z',
    finished_at: null,
  }
  exports.set(id, { item, polls: 0 })
  return HttpResponse.json({ data: item }, { status: 202 })
}

/**
 * `/v1/reports/{report}/exports`, `/v1/exports/tickets` and `/v1/exports/{export}` (M3-09). An export is
 * `running` on the first poll and `ready` on the second, so a test sees the whole cycle.
 */
export const exportHandlers = [
  http.post(apiUrl('/reports/{report}/exports'), async ({ request, params }) => {
    const body = (await request.json()) as Record<string, unknown>
    exportRequests.push({ path: `/reports/${String(params.report)}/exports`, body })
    return queue(String(params.report), body)
  }),
  http.post(apiUrl('/exports/tickets'), async ({ request }) => {
    const body = (await request.json()) as Record<string, unknown>
    exportRequests.push({ path: '/exports/tickets', body })
    return queue('tickets-list', body)
  }),
  http.get(apiUrl('/exports/{export}'), ({ params }) => {
    const entry = exports.get(String(params.export))
    if (!entry) return problem(404, 'not_found', { title: 'Not found' })
    entry.polls += 1
    if (entry.polls === 1) entry.item = { ...entry.item, state: 'running' }
    else if (entry.item.state !== 'ready') {
      const name = entry.item.report_key === 'tickets-list' ? 'Tickets' : 'Ticket volume'
      entry.item = {
        ...entry.item,
        state: 'ready',
        row_count: 12,
        media_id: EXPORT_MEDIA_ID,
        file_name: `${name} 2026-09-21 1445.${entry.item.format}`,
        size_bytes: 2048,
        download_url: `https://api.test/v1/media/${EXPORT_MEDIA_ID}/download`,
        finished_at: '2026-09-21T09:00:04Z',
      }
    }
    return HttpResponse.json({ data: entry.item })
  }),
]

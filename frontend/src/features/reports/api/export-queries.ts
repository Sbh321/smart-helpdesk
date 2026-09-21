import { queryOptions } from '@tanstack/react-query'
import { api, apiUrl, unwrap } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'
import type { ApiListQuery } from '@/lib/list-params'
import type { RunBody } from '../report-params'

export type ReportExport = components['schemas']['ReportExportResource']
export type ExportFormat = ReportExport['format']
export type TicketExportBody = components['schemas']['ExportTicketsRequest']

/** How often a queued export is asked about; it stops once the export is ready or failed. */
export const EXPORT_POLL_MS = 2_000

export function isExportDone(item: ReportExport | undefined): boolean {
  return item?.state === 'ready' || item?.state === 'failed'
}

/** The file, through the Media download route: it checks access and signs a five-minute URL per click. */
export function exportDownloadUrl(mediaId: string): string {
  return apiUrl(`/v1/media/${mediaId}/download`)
}

/** `POST /v1/reports/{report}/exports` with the report page's current parameters. */
export function exportReport(key: string, format: ExportFormat, parameters: RunBody): Promise<ReportExport> {
  return unwrap(
    api().POST('/reports/{report}/exports', {
      params: { path: { report: key } },
      body: { format, parameters },
    }),
  )
}

/** The list's API query (`filter[status]`, `search`, `sort`) as the export body; paging is dropped. */
export function toTicketExportBody(query: ApiListQuery, format: ExportFormat): TicketExportBody {
  const filter: Record<string, string> = {}
  for (const [key, value] of Object.entries(query)) {
    const match = /^filter\[(.+)\]$/.exec(key)
    if (match?.[1] && typeof value === 'string' && value !== '') filter[match[1]] = value
  }
  return {
    format,
    sort: query.sort,
    ...(query.search ? { search: query.search } : {}),
    ...(Object.keys(filter).length > 0 ? { filter } : {}),
  }
}

/** `POST /v1/exports/tickets` with the ticket list's current filters, search and sort. */
export function exportTickets(query: ApiListQuery, format: ExportFormat): Promise<ReportExport> {
  return unwrap(api().POST('/exports/tickets', { body: toTicketExportBody(query, format) }))
}

export const exportQueries = {
  detail: (tenantId: string, id: string) =>
    queryOptions({
      queryKey: queryKeys.exports.detail(tenantId, id),
      queryFn: () => unwrap(api().GET('/exports/{export}', { params: { path: { export: id } } })),
      refetchInterval: (query) => (isExportDone(query.state.data) ? false : EXPORT_POLL_MS),
    }),
}

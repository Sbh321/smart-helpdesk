import { keepPreviousData, queryOptions } from '@tanstack/react-query'
import { api, unwrap, unwrapBody } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import type { components } from '@/lib/api/schema'
import { type Period, type ReportParams, type RunBody, toRecordsQuery } from '../report-params'

export type ReportDefinition = components['schemas']['ReportDefinitionResource']
export type ReportRun = components['schemas']['ReportRunResource']
export type ReportRow = ReportRun['rows'][number]
export type ReportRecord = components['schemas']['ReportRecordResource']
export type Dashboard = components['schemas']['DashboardResource']
export type DashboardKpi = Dashboard['kpis'][number]
export type DashboardSeries = Dashboard['series'][number]
export type ReportMeasure = ReportDefinition['measures'][number]

/** The report API (docs/04-domain/reporting.md §API). Runs are cached server-side for five minutes. */
export const reportQueries = {
  catalogue: (tenantId: string) =>
    queryOptions({
      queryKey: queryKeys.reports.catalogue(tenantId),
      queryFn: () => unwrap(api().GET('/reports')),
      staleTime: 5 * 60_000,
    }),
  run: (tenantId: string, key: string, body: RunBody) =>
    queryOptions({
      queryKey: queryKeys.reports.run(tenantId, key, body),
      queryFn: () => unwrap(api().POST('/reports/{report}/run', { params: { path: { report: key } }, body })),
      placeholderData: keepPreviousData,
      staleTime: 60_000,
    }),
  records: (tenantId: string, key: string, params: ReportParams, rowKey: string, page: number) => {
    const query = toRecordsQuery(params, rowKey, page)
    return queryOptions({
      queryKey: queryKeys.reports.records(tenantId, key, query),
      queryFn: () =>
        unwrapBody(api().GET('/reports/{report}/records', { params: { path: { report: key }, query } })),
      placeholderData: keepPreviousData,
    })
  },
  dashboard: (tenantId: string, period: Period) =>
    queryOptions({
      queryKey: queryKeys.reports.dashboard(tenantId, period),
      queryFn: () => unwrap(api().GET('/dashboard', { params: { query: { period } } })),
      placeholderData: keepPreviousData,
      staleTime: 60_000,
    }),
}

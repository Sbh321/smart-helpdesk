import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { LayoutDashboardIcon } from 'lucide-react'
import { ChartCard } from '@/components/shared/chart-card'
import { SelectFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { KpiTile } from '@/components/shared/kpi-tile'
import { PageHeader } from '@/components/shared/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { type DashboardSeries, reportQueries } from '../api/report-queries'
import { describeChange, formatMeasure } from '../format'
import { DEFAULT_PERIOD, PERIODS, type Period } from '../report-params'
import { ChartTable } from './chart-table'
import { SeriesChart, type SeriesKind } from './series-chart'

const text = copy.dashboard

/** The dashboard series say `line`, `stacked_area` or `bar`, the catalogue's words. */
function seriesKind(chart: string): SeriesKind {
  if (chart === 'line') return 'line'
  if (chart === 'stacked_area') return 'area'
  return 'bar'
}

function ReportLink({
  workspace,
  report,
  period,
  group,
  label,
}: {
  workspace: string
  report: string
  period: Period
  group?: string
  label: string
}) {
  return (
    <Link
      to="/$workspace/reports/$reportKey"
      params={{ workspace, reportKey: report }}
      search={{
        ...(period !== DEFAULT_PERIOD ? { period } : {}),
        ...(group !== undefined ? { group } : {}),
      }}
      className="text-sm font-medium text-primary underline-offset-4 hover:underline"
    >
      {label}
    </Link>
  )
}

function SeriesCard({
  workspace,
  series,
  period,
}: {
  workspace: string
  series: DashboardSeries
  period: Period
}) {
  const dimensionLabel = series.parameters.group
  return (
    <ChartCard
      title={series.title}
      description={series.report_title}
      showTableLabel={copy.reports.showTable}
      hideTableLabel={copy.reports.hideTable}
      actions={
        <ReportLink
          workspace={workspace}
          report={series.report}
          period={period}
          group={series.parameters.group}
          label={fill(text.openReportFor, { title: series.report_title })}
        />
      }
      table={
        <ChartTable
          caption={series.title}
          dimensionLabel={dimensionLabel.charAt(0).toUpperCase() + dimensionLabel.slice(1)}
          rows={series.rows}
          measures={series.measures}
        />
      }
    >
      {series.rows.length === 0 ? (
        <p className="flex h-64 items-center justify-center text-sm text-muted-foreground">
          {copy.reports.noRows}
        </p>
      ) : (
        <SeriesChart
          kind={seriesKind(series.chart)}
          rows={series.rows}
          measures={series.measures}
          label={series.title}
        />
      )}
    </ChartCard>
  )
}

/**
 * The workspace landing page (FR-ANL, roadmap M3-01): KPI tiles with the change against the previous
 * period and six charts, all from `GET /v1/dashboard`, which composes catalogue reports. The period is
 * `?period=` in the URL; every tile and chart links to its full report with the same period.
 */
export function DashboardScreen({ workspace, period }: { workspace: string; period: Period }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const allowed = useCan('reports.view')
  const tenantId = session?.tenant.id ?? ''
  const dashboard = useQuery({
    ...reportQueries.dashboard(tenantId, period),
    enabled: allowed && tenantId !== '',
  })
  const data = dashboard.data
  const periodOptions = PERIODS.map((value) => ({ value, label: copy.reports.periods[value] ?? value }))

  const header = (
    <PageHeader
      title={text.title}
      description={session ? `${text.description} — ${session.tenant.name}` : undefined}
      actions={
        allowed ? (
          <SelectFilter
            label={text.periodLabel}
            options={periodOptions}
            value={period}
            defaultValue={DEFAULT_PERIOD}
            onChange={(next) =>
              void navigate({
                to: '/$workspace',
                params: { workspace },
                search: next === undefined ? {} : { period: next as Period },
              })
            }
          />
        ) : null
      }
    />
  )

  if (!allowed) {
    return (
      <>
        {header}
        <EmptyState icon={LayoutDashboardIcon} title={text.noAccessTitle} description={text.noAccessBody} />
      </>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      {header}
      {dashboard.isError && !data ? (
        <ErrorState
          title={text.loadFailed}
          error={dashboard.error}
          onRetry={() => void dashboard.refetch()}
        />
      ) : (
        <>
          <section aria-labelledby="dashboard-kpis" className="flex flex-col gap-3">
            <h2 id="dashboard-kpis" className="text-base font-semibold">
              {text.kpis}
            </h2>
            {!data ? (
              <div className="grid grid-cols-[repeat(auto-fill,minmax(13rem,1fr))] gap-3" aria-busy="true">
                <p role="status" className="sr-only">
                  {text.loading}
                </p>
                {[0, 1, 2, 3, 4, 5, 6, 7].map((index) => (
                  <Skeleton key={index} className="h-32" />
                ))}
              </div>
            ) : (
              <div
                className="grid grid-cols-[repeat(auto-fill,minmax(13rem,1fr))] gap-3"
                aria-busy={dashboard.isFetching}
              >
                {data.kpis.map((kpi) => (
                  <KpiTile
                    key={kpi.key}
                    label={kpi.label}
                    value={formatMeasure(kpi.value, kpi.unit)}
                    change={describeChange(kpi.value, kpi.previous, kpi.unit)}
                    noChange={copy.reports.change.none}
                    footer={
                      <ReportLink
                        workspace={workspace}
                        report={kpi.report}
                        period={period}
                        label={fill(text.openReportFor, { title: kpi.label })}
                      />
                    }
                  />
                ))}
              </div>
            )}
          </section>
          <section aria-labelledby="dashboard-charts" className="flex flex-col gap-3">
            <h2 id="dashboard-charts" className="text-base font-semibold">
              {text.charts}
            </h2>
            {!data ? (
              <div className="grid gap-4 xl:grid-cols-2">
                {[0, 1, 2, 3, 4, 5].map((index) => (
                  <Skeleton key={index} className="h-80" />
                ))}
              </div>
            ) : data.series.length === 0 ? (
              <EmptyState icon={LayoutDashboardIcon} title={text.emptyTitle} description={text.emptyBody} />
            ) : (
              <div className="grid gap-4 xl:grid-cols-2">
                {data.series.map((series) => (
                  <SeriesCard key={series.key} workspace={workspace} series={series} period={period} />
                ))}
              </div>
            )}
          </section>
        </>
      )}
    </div>
  )
}

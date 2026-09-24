import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArrowUpRightIcon, LayoutDashboardIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { ChartCard } from '@/components/shared/chart-card'
import { SeriesChart, type SeriesKind } from '@/components/shared/charts/series-chart'
import { Sparkline } from '@/components/shared/charts/sparkline'
import { SelectFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { KpiTile, kpiTileLinkClassName } from '@/components/shared/kpi-tile'
import { PageHeader } from '@/components/shared/page-header'
import { buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { labelRows, timeDimension } from '@/lib/format/dimension-labels'
import { describeChange, formatMeasure } from '@/lib/format/measure'
import { type DashboardKpi, type DashboardSeries, reportQueries } from '../api/report-queries'
import { DEFAULT_PERIOD, PERIODS, type Period } from '../report-params'
import { ChartTable } from './chart-table'

const text = copy.dashboard

/** The dashboard series say `line`, `stacked_area`, `stacked_bar` or `bar`, the catalogue's words. */
function seriesKind(chart: string): SeriesKind {
  if (chart === 'line') return 'line'
  if (chart === 'stacked_area') return 'area'
  if (chart === 'stacked_bar') return 'stacked_bar'
  return 'bar'
}

/** The search of a report link: the dashboard's period and, for a chart, its grouping. */
function reportSearch(period: Period, group?: string) {
  return {
    ...(period !== DEFAULT_PERIOD ? { period } : {}),
    ...(group !== undefined ? { group } : {}),
  }
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
  const group = series.parameters.group
  const dimensionLabel = group.charAt(0).toUpperCase() + group.slice(1)
  // Dates and empty buckets in words (M4-09), as on the report page.
  const labelled = labelRows(series.rows, timeDimension(group, dimensionLabel))
  const tableRows = labelled.map(({ row, long }) => ({ ...row, label: long }))
  const chartRows = labelled.map(({ row, short, long }) => ({ ...row, label: short, fullLabel: long }))
  const time = timeDimension(group, dimensionLabel).is_time
  return (
    <ChartCard
      title={series.title}
      description={series.report_title}
      showTableLabel={copy.reports.showTable}
      hideTableLabel={copy.reports.hideTable}
      actions={
        // The chart's title says what it is; the icon opens it as a full report with the same period.
        <Link
          to="/$workspace/reports/$reportKey"
          params={{ workspace, reportKey: series.report }}
          search={reportSearch(period, series.parameters.group)}
          aria-label={fill(text.openReportFor, { title: series.title })}
          title={fill(text.openReportFor, { title: series.title })}
          className={buttonVariants({ variant: 'ghost', size: 'icon-sm' })}
        >
          <ArrowUpRightIcon aria-hidden="true" />
        </Link>
      }
      table={
        <ChartTable
          caption={series.title}
          dimensionLabel={dimensionLabel}
          rows={tableRows}
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
          rows={chartRows}
          measures={series.measures}
          label={series.title}
          time={time}
        />
      )}
    </ChartCard>
  )
}

/** A group of charts under its own heading; nothing when the caller may run none of them. */
function ChartSection({
  id,
  title,
  series,
  workspace,
  period,
}: {
  id: string
  title: string
  series: readonly DashboardSeries[]
  workspace: string
  period: Period
}) {
  if (series.length === 0) return null
  return (
    <section aria-labelledby={id} className="flex flex-col gap-3">
      <h2 id={id} className="text-base font-semibold">
        {title}
      </h2>
      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {series.map((item) => (
          <SeriesCard key={item.key} workspace={workspace} series={item} period={period} />
        ))}
      </div>
    </section>
  )
}

/** The sparkline of a tile: its own measure in the series the API names, or nothing. */
function tileTrend(kpi: DashboardKpi, series: readonly DashboardSeries[]): (number | null)[] | null {
  if (!kpi.trend_series) return null
  const trend = series.find((item) => item.key === kpi.trend_series)
  if (!trend) return null
  return trend.rows.map((row) => {
    const value = row.values[kpi.measure]
    return typeof value === 'number' ? value : null
  })
}

/**
 * The workspace landing page (FR-ANL, roadmap M3-01, reorganised in M4-08) in three levels, so the first
 * screen answers "is today under control" before anything else:
 *
 * 1. **Right now** (`now`, owned by the tickets feature and passed in by the route): live queue counts.
 * 2. **This period**: the KPI tiles of `GET /v1/dashboard`, each tile the link to its report, with a
 *    sparkline only where the API names a series carrying that measure (`trend_series`).
 * 3. **Trends** and **Breakdown**: the series, grouped by the `section` the API gives them.
 *
 * The period is `?period=` in the URL and every link keeps it. No number is computed here.
 */
export function DashboardScreen({
  workspace,
  period,
  now,
}: {
  workspace: string
  period: Period
  /** The live "Right now" block; the route passes the tickets feature's `TicketsRightNow`. */
  now?: ReactNode
}) {
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
      <div className="flex flex-col gap-6">
        {header}
        {now}
        <EmptyState icon={LayoutDashboardIcon} title={text.noAccessTitle} description={text.noAccessBody} />
      </div>
    )
  }

  const series = data?.series ?? []
  return (
    <div className="flex flex-col gap-6">
      {header}
      {now}
      {dashboard.isError && !data ? (
        <ErrorState
          title={text.loadFailed}
          error={dashboard.error}
          onRetry={() => void dashboard.refetch()}
        />
      ) : (
        <>
          <section aria-labelledby="dashboard-kpis" className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline gap-x-3">
              <h2 id="dashboard-kpis" className="text-base font-semibold">
                {text.kpis}
              </h2>
              <p className="text-sm text-muted-foreground">{copy.reports.periods[period] ?? period}</p>
            </div>
            {!data ? (
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy="true">
                <p role="status" className="sr-only">
                  {text.loading}
                </p>
                {[0, 1, 2, 3, 4, 5, 6, 7].map((index) => (
                  <Skeleton key={index} className="h-28" />
                ))}
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy={dashboard.isFetching}>
                {data.kpis.map((kpi) => {
                  const trend = tileTrend(kpi, series)
                  return (
                    <Link
                      key={kpi.key}
                      to="/$workspace/reports/$reportKey"
                      params={{ workspace, reportKey: kpi.report }}
                      search={reportSearch(period)}
                      className={kpiTileLinkClassName}
                    >
                      <KpiTile
                        compact
                        label={kpi.label}
                        value={formatMeasure(kpi.value, kpi.unit)}
                        change={describeChange(kpi.value, kpi.previous, kpi.unit)}
                        noChange={copy.reports.change.none}
                        sparkline={trend ? <Sparkline values={trend} /> : undefined}
                      />
                    </Link>
                  )
                })}
              </div>
            )}
          </section>
          {!data ? (
            <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
              {[0, 1, 2].map((index) => (
                <Skeleton key={index} className="h-80" />
              ))}
            </div>
          ) : series.length === 0 ? (
            <EmptyState icon={LayoutDashboardIcon} title={text.emptyTitle} description={text.emptyBody} />
          ) : (
            <>
              <ChartSection
                id="dashboard-trends"
                title={text.trends}
                series={series.filter((item) => item.section === 'trend')}
                workspace={workspace}
                period={period}
              />
              <ChartSection
                id="dashboard-breakdown"
                title={text.breakdown}
                series={series.filter((item) => item.section !== 'trend')}
                workspace={workspace}
                period={period}
              />
            </>
          )}
        </>
      )}
    </div>
  )
}

import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useNavigate, useRouter } from '@tanstack/react-router'
import { ArrowLeftIcon, PrinterIcon } from 'lucide-react'
import { SeriesChart } from '@/components/shared/charts/series-chart'
import { SelectFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { KpiTile } from '@/components/shared/kpi-tile'
import { PageHeader } from '@/components/shared/page-header'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { labelRows } from '@/lib/format/dimension-labels'
import { describeChange, formatMeasure } from '@/lib/format/measure'
import { exportReport } from '../api/export-queries'
import { type ReportDefinition, type ReportRow, reportQueries } from '../api/report-queries'
import { chartKindFor, chartMeasuresFor } from '../chart-choice'
import {
  ALL_RECORDS,
  parseReportSearch,
  type ReportParams,
  toReportSearch,
  toRunBody,
} from '../report-params'
import { ExportControls } from './export-controls'
import { HeatmapChart } from './heatmap-chart'
import { ParameterBar } from './parameter-bar'
import { RecordsDialog } from './records-dialog'
import { ReportTable } from './report-table'
import { useFilterOptions } from './use-filter-options'

const text = copy.reports

/** The chart measure select's "default measures" option; never sent or stored. */
const DEFAULT_CHART = 'default'

function BackToReports({ workspace }: { workspace: string }) {
  return (
    <Link
      to="/$workspace/reports"
      params={{ workspace }}
      className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground print:hidden"
    >
      <ArrowLeftIcon aria-hidden="true" className="size-4" />
      {text.back}
    </Link>
  )
}

/** `/$workspace/reports/$reportKey`: one catalogue report with its parameters in the URL. */
export function ReportScreen({ workspace, reportKey }: { workspace: string; reportKey: string }) {
  const allowed = useCan('reports.view')
  const tenantId = useSession().session?.tenant.id ?? ''
  const catalogue = useQuery({ ...reportQueries.catalogue(tenantId), enabled: allowed && tenantId !== '' })
  const definition = catalogue.data?.find((report) => report.key === reportKey)

  if (!allowed) {
    return (
      <>
        <PageHeader title={text.title} />
        <ForbiddenState />
      </>
    )
  }
  if (catalogue.isError) {
    return (
      <>
        <PageHeader title={text.title} eyebrow={<BackToReports workspace={workspace} />} />
        <ErrorState
          title={text.catalogueFailed}
          error={catalogue.error}
          onRetry={() => void catalogue.refetch()}
        />
      </>
    )
  }
  if (!catalogue.data) {
    return (
      <>
        <PageHeader title={text.title} eyebrow={<BackToReports workspace={workspace} />} />
        <Skeleton className="h-96 w-full" />
      </>
    )
  }
  if (!definition) {
    return (
      <>
        <PageHeader title={text.title} eyebrow={<BackToReports workspace={workspace} />} />
        <EmptyState title={copy.states.notFound.title} description={text.notFound} />
      </>
    )
  }
  return <ReportView workspace={workspace} definition={definition} tenantId={tenantId} />
}

function ReportView({
  workspace,
  definition,
  tenantId,
}: {
  workspace: string
  definition: ReportDefinition
  tenantId: string
}) {
  const router = useRouter()
  const navigate = useNavigate()
  const canExport = useCan('reports.export')
  const timeZone = useSession().session?.tenant.timezone ?? 'UTC'
  const filterKeys = definition.filters.map((filter) => filter.key)
  const rawSearch = useLocation({ select: (location) => location.search }) as Record<string, unknown>
  const params = parseReportSearch(rawSearch, filterKeys)
  const filterChoices = useFilterOptions(definition)
  // Reports of the present (ageing, at-risk) say so themselves (M4-09).
  const periodApplies = definition.period_applies

  const write = (next: ReportParams, replace = false) => {
    const current = router.state.location.search as Record<string, unknown>
    void navigate({ to: '.', search: toReportSearch(next, filterKeys, current) as never, replace })
  }
  // A parameter change closes any drill-down: its records would belong to other numbers.
  const update = (patch: Partial<ReportParams>) => {
    const current = parseReportSearch(router.state.location.search as Record<string, unknown>, filterKeys)
    write({ ...current, ...patch, drill: undefined, drillPage: 1 })
  }
  const drill = (rowKey: string | undefined, page = 1, measure?: string) => {
    const current = parseReportSearch(router.state.location.search as Record<string, unknown>, filterKeys)
    write({
      ...current,
      drill: rowKey,
      drillMeasure: rowKey === undefined ? undefined : measure,
      drillPage: page,
    })
  }

  const run = useQuery({
    ...reportQueries.run(tenantId, definition.key, toRunBody(params)),
    enabled: tenantId !== '',
  })
  const group = params.group ?? definition.default_dimension
  const dimension = definition.dimensions.find((candidate) => candidate.key === group)
  const measures = definition.measures
  const kind = chartKindFor(definition.chart, group, dimension?.is_time ?? false)
  const chartMeasures = chartMeasuresFor(measures, params.chart, definition.chart_measures)
  const defaultMeasures = chartMeasuresFor(measures, undefined, definition.chart_measures)
  const canDrill = definition.drill_down_to !== null
  // Rows in words (M4-09): dates in the app format, "No team" for the empty bucket. The chart gets the
  // short label for its axis and the long one for its tooltip; the table and drill-down the long one.
  const labelled = run.data ? labelRows(run.data.rows, dimension) : undefined
  const rows = labelled?.map(({ row, long }) => ({ ...row, label: long }))
  const chartRows = labelled?.map(({ row, short, long }) => ({ ...row, label: short, fullLabel: long }))
  const drillRow = params.drill !== undefined ? rows?.find((row) => row.key === params.drill) : undefined
  const onDrill = canDrill ? (row: ReportRow, measure: string) => drill(row.key, 1, measure) : undefined
  const drillMeasureLabel = measures.find((measure) => measure.key === params.drillMeasure)?.label

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={definition.title}
        description={definition.description}
        eyebrow={<BackToReports workspace={workspace} />}
        actions={
          <div className="flex flex-wrap items-center gap-2 print:hidden">
            <Button type="button" variant="outline" size="sm" onClick={() => window.print()}>
              <PrinterIcon aria-hidden="true" />
              {text.print}
            </Button>
            {/* Exports are queued jobs delivered through the media library (roadmap M3-09). */}
            {canExport ? (
              <ExportControls
                className="flex flex-wrap items-center gap-2"
                onRequest={(format) => exportReport(definition.key, format, toRunBody(params))}
              />
            ) : null}
          </div>
        }
      />
      <ParameterBar
        definition={definition}
        params={params}
        filterChoices={filterChoices}
        periodApplies={periodApplies}
        onChange={update}
      />
      <p className="-mt-3 text-xs text-muted-foreground">
        {periodApplies ? fill(text.timezone, { timezone: timeZone }) : text.nowReport}
      </p>

      {run.isError && !run.data ? (
        <ErrorState title={text.loadFailed} error={run.error} onRetry={() => void run.refetch()} />
      ) : (
        <>
          <section aria-labelledby="report-totals" className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="report-totals" className="text-base font-semibold">
                {text.totals}
              </h2>
              {canDrill && run.data ? (
                <Button
                  type="button"
                  variant="link"
                  size="sm"
                  className="print:hidden"
                  onClick={() => drill(ALL_RECORDS)}
                >
                  {text.viewAllRecords}
                </Button>
              ) : null}
            </div>
            <div
              className="grid grid-cols-[repeat(auto-fill,minmax(12rem,1fr))] gap-3"
              aria-busy={run.isFetching}
            >
              {run.data
                ? measures.map((measure) => {
                    const value = run.data.totals[measure.key] ?? null
                    const previous = run.data.previous ? (run.data.previous[measure.key] ?? null) : null
                    const change = run.data.previous ? describeChange(value, previous, measure.unit) : null
                    return (
                      <KpiTile
                        key={measure.key}
                        compact
                        label={measure.label}
                        value={formatMeasure(value, measure.unit)}
                        change={change}
                        {...(params.compare && periodApplies && !change
                          ? { noChange: text.change.noneHint }
                          : {})}
                      />
                    )
                  })
                : measures.slice(0, 4).map((measure) => <Skeleton key={measure.key} className="h-24" />)}
            </div>
          </section>

          {kind !== 'table' ? (
            <section
              aria-labelledby="report-chart"
              className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="report-chart" className="text-base font-semibold">
                  {text.chart}
                </h2>
                {measures.length > 1 ? (
                  <div className="print:hidden">
                    <SelectFilter
                      label={text.chartMeasure}
                      options={[
                        {
                          value: DEFAULT_CHART,
                          label: fill(text.chartDefaultNamed, {
                            measures: defaultMeasures.map((measure) => measure.label).join(', '),
                          }),
                        },
                        ...measures.map((measure) => ({ value: measure.key, label: measure.label })),
                      ]}
                      value={params.chart}
                      defaultValue={DEFAULT_CHART}
                      onChange={(chart) => update({ chart })}
                    />
                  </div>
                ) : null}
              </div>
              {!rows || !chartRows ? (
                <Skeleton className="h-64 w-full" />
              ) : rows.length === 0 ? (
                <p className="py-12 text-center text-sm text-muted-foreground">{text.noRows}</p>
              ) : kind === 'heatmap' && chartMeasures[0] ? (
                <HeatmapChart
                  rows={rows}
                  measure={chartMeasures[0]}
                  label={fill(text.heatmapLabel, { title: definition.title })}
                />
              ) : kind !== 'heatmap' ? (
                <SeriesChart
                  kind={kind}
                  rows={chartRows}
                  measures={chartMeasures}
                  label={definition.title}
                  time={dimension?.is_time ?? false}
                />
              ) : null}
              <p className="text-xs text-muted-foreground">{canDrill ? text.drillHint : null}</p>
            </section>
          ) : null}

          <section aria-labelledby="report-data" className="flex flex-col gap-3">
            <h2 id="report-data" className="text-base font-semibold">
              {text.table}
            </h2>
            {run.data?.truncated ? <p className="text-sm text-muted-foreground">{text.truncated}</p> : null}
            <ReportTable
              id={definition.key}
              title={definition.title}
              dimensionLabel={dimension?.label ?? group}
              rows={rows}
              measures={measures}
              isFetching={run.isFetching && run.isPlaceholderData}
              error={run.error}
              onRetry={() => void run.refetch()}
              onDrill={onDrill}
            />
          </section>
        </>
      )}

      {canDrill ? (
        <RecordsDialog
          workspace={workspace}
          reportKey={definition.key}
          params={params}
          rowKey={params.drill}
          rowLabel={drillRow?.label}
          measureLabel={drillMeasureLabel}
          onPage={(page) => drill(params.drill, page, params.drillMeasure)}
          onClose={() => drill(undefined)}
        />
      ) : null}
    </div>
  )
}

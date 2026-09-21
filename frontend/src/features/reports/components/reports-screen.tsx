import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { ChevronRightIcon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { PageHeader } from '@/components/shared/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { useCan, useSession } from '@/lib/auth'
import { type ReportDefinition, reportQueries } from '../api/report-queries'

const text = copy.reports

/** Catalogue groups in the order of docs/04-domain/reporting.md. */
const GROUP_ORDER = ['tickets', 'contacts', 'agents', 'sla', 'media', 'administration']

function grouped(reports: readonly ReportDefinition[]): [string, ReportDefinition[]][] {
  const groups = new Map<string, ReportDefinition[]>()
  for (const report of reports) groups.set(report.group, [...(groups.get(report.group) ?? []), report])
  const rank = (group: string) => {
    const index = GROUP_ORDER.indexOf(group)
    return index === -1 ? GROUP_ORDER.length : index
  }
  return [...groups.entries()].sort(([a], [b]) => rank(a) - rank(b))
}

/** `/$workspace/reports`: the reports the caller may run, grouped by what they describe. */
export function ReportsScreen({ workspace }: { workspace: string }) {
  const allowed = useCan('reports.view')
  const tenantId = useSession().session?.tenant.id ?? ''
  const catalogue = useQuery({ ...reportQueries.catalogue(tenantId), enabled: allowed && tenantId !== '' })

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={text.title} description={text.description} />
      {!allowed ? (
        <ForbiddenState />
      ) : catalogue.isError ? (
        <ErrorState
          title={text.catalogueFailed}
          error={catalogue.error}
          onRetry={() => void catalogue.refetch()}
        />
      ) : !catalogue.data ? (
        <div className="grid gap-3 md:grid-cols-2" aria-busy="true">
          {[0, 1, 2, 3].map((index) => (
            <Skeleton key={index} className="h-20" />
          ))}
        </div>
      ) : catalogue.data.length === 0 ? (
        <EmptyState title={text.catalogueEmpty} />
      ) : (
        <nav aria-label={text.catalogueLabel} className="flex flex-col gap-8">
          {grouped(catalogue.data).map(([group, reports]) => (
            <section key={group} aria-labelledby={`reports-${group}`} className="flex flex-col gap-3">
              <h2 id={`reports-${group}`} className="text-base font-semibold">
                {text.groups[group] ?? group}
              </h2>
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {reports.map((report) => (
                  <li key={report.key}>
                    <Link
                      to="/$workspace/reports/$reportKey"
                      params={{ workspace, reportKey: report.key }}
                      className="group flex h-full items-start justify-between gap-3 rounded-lg border border-border bg-surface p-4 hover:bg-muted/50"
                    >
                      <span className="flex flex-col gap-1">
                        <span className="font-medium">{report.title}</span>
                        <span className="text-sm text-muted-foreground">{report.description}</span>
                        <span className="text-xs text-muted-foreground">
                          {report.key.toUpperCase()} · {text.charts[report.chart] ?? report.chart}
                        </span>
                      </span>
                      <ChevronRightIcon
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                      />
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </nav>
      )}
    </div>
  )
}

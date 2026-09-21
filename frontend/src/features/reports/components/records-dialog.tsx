import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { ErrorState } from '@/components/shared/error-state'
import { StatusBadge } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useSession } from '@/lib/auth'
import { type ReportRecord, reportQueries } from '../api/report-queries'
import { ALL_RECORDS, type ReportParams } from '../report-params'

const text = copy.reports.records

export interface RecordsDialogProps {
  workspace: string
  reportKey: string
  params: ReportParams
  /** The row key whose records are open (`*` for the whole period), from the URL. */
  rowKey: string | undefined
  /** The row's label, when the run is loaded. */
  rowLabel: string | undefined
  onPage: (page: number) => void
  onClose: () => void
}

/** The record's own page, where the SPA has one (agents get theirs with M3-21). */
function RecordLink({ workspace, record }: { workspace: string; record: ReportRecord }) {
  const className = 'font-medium underline-offset-4 hover:underline'
  switch (record.entity) {
    case 'tickets':
      return (
        <Link
          to="/$workspace/tickets/$ticketId"
          params={{ workspace, ticketId: record.id }}
          className={className}
        >
          {record.label}
        </Link>
      )
    case 'contacts':
      return (
        <Link
          to="/$workspace/contacts/$contactId"
          params={{ workspace, contactId: record.id }}
          className={className}
        >
          {record.label}
        </Link>
      )
    case 'organizations':
      return (
        <Link
          to="/$workspace/organizations/$organizationId"
          params={{ workspace, organizationId: record.id }}
          className={className}
        >
          {record.label}
        </Link>
      )
    default:
      return <span className="font-medium">{record.label}</span>
  }
}

/**
 * Drill-down: the records behind one number of a report (`GET /v1/reports/{key}/records`), 50 a page,
 * each linking to its own page. Open while `drill` is in the URL, so the back button closes it.
 */
export function RecordsDialog({
  workspace,
  reportKey,
  params,
  rowKey,
  rowLabel,
  onPage,
  onClose,
}: RecordsDialogProps) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const open = rowKey !== undefined
  const records = useQuery({
    ...reportQueries.records(tenantId, reportKey, params, rowKey ?? ALL_RECORDS, params.drillPage),
    enabled: open && tenantId !== '',
  })
  const total = records.data?.meta.total ?? 0
  const perPage = records.data?.meta.per_page ?? 50
  const pages = Math.max(1, Math.ceil(total / perPage))
  const title =
    rowKey === ALL_RECORDS || rowKey === undefined
      ? text.totalTitle
      : fill(text.title, { label: rowLabel ?? rowKey })

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            {records.data ? fill(text.description, { total }) : copy.dataTable.loading}
          </DialogDescription>
        </DialogHeader>
        {records.isPending ? (
          <Skeleton className="h-40 w-full" />
        ) : records.isError ? (
          <ErrorState title={text.loadFailed} error={records.error} onRetry={() => void records.refetch()} />
        ) : records.data.data.length === 0 ? (
          <p className="text-sm text-muted-foreground">{text.empty}</p>
        ) : (
          <ul
            aria-label={text.label}
            aria-busy={records.isFetching}
            className="flex flex-col divide-y divide-border"
          >
            {records.data.data.map((record) => (
              <li key={record.id} className="flex items-center justify-between gap-3 py-2">
                <div className="flex min-w-0 flex-col">
                  <RecordLink workspace={workspace} record={record} />
                  {record.subtitle ? (
                    <span className="truncate text-xs text-muted-foreground">{record.subtitle}</span>
                  ) : null}
                </div>
                {record.entity === 'tickets' && record.status ? <StatusBadge status={record.status} /> : null}
              </li>
            ))}
          </ul>
        )}
        {pages > 1 ? (
          <nav aria-label={text.label} className="flex items-center justify-between gap-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={params.drillPage <= 1}
              onClick={() => onPage(params.drillPage - 1)}
            >
              {text.previous}
            </Button>
            <span className="text-sm text-muted-foreground">
              {fill(text.page, { page: params.drillPage, pages })}
            </span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={params.drillPage >= pages}
              onClick={() => onPage(params.drillPage + 1)}
            >
              {text.next}
            </Button>
          </nav>
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

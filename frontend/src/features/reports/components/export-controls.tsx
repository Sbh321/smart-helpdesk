import { useQuery } from '@tanstack/react-query'
import { DownloadIcon, FileSpreadsheetIcon, Loader2Icon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { ApiError } from '@/lib/api/errors'
import { useSession } from '@/lib/auth'
import {
  type ExportFormat,
  exportDownloadUrl,
  exportQueries,
  isExportDone,
  type ReportExport,
} from '../api/export-queries'

const text = copy.exports

/** The message of a refused export request: the first field error (a list above the cap), else the detail. */
function requestFailure(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.fieldErrors)[0]?.[0]
    return first ?? error.detail ?? text.requestFailed
  }
  return text.requestFailed
}

/**
 * "Export CSV" and "Export XLSX" (docs/04-domain/reporting.md §Exports): the request queues a job, a
 * toast says so, the export is polled until it is ready or failed, and the file is offered as a link
 * (the Media download route signs a fresh short-lived URL on each click). One export at a time; the
 * status line is a polite live region.
 */
export function ExportControls({
  onRequest,
  className,
}: {
  onRequest: (format: ExportFormat) => Promise<ReportExport>
  className?: string
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const [exportId, setExportId] = useState<string | null>(null)
  const [requesting, setRequesting] = useState<ExportFormat | null>(null)
  const current = useQuery({ ...exportQueries.detail(tenantId, exportId ?? ''), enabled: exportId !== null })
  const item = current.data
  const busy = requesting !== null || (exportId !== null && !isExportDone(item) && !current.isError)

  // Announce the outcome once per export.
  const announced = useRef<string | null>(null)
  useEffect(() => {
    if (!item || !isExportDone(item) || announced.current === item.id) return
    announced.current = item.id
    if (item.state === 'ready') toast.success(text.ready)
    else toast.error(text.failed[item.error ?? 'failed'] ?? text.failed.failed)
  }, [item])

  const start = async (format: ExportFormat) => {
    setRequesting(format)
    try {
      const queued = await onRequest(format)
      setExportId(queued.id)
      toast.success(text.queued)
    } catch (error) {
      toast.error(requestFailure(error))
    } finally {
      setRequesting(null)
    }
  }

  return (
    <div className={className ?? 'flex flex-wrap items-center gap-2 print:hidden'}>
      {(['csv', 'xlsx'] as const).map((format) => (
        <Button
          key={format}
          type="button"
          variant="outline"
          size="sm"
          disabled={busy}
          onClick={() => void start(format)}
        >
          {requesting === format ? (
            <Loader2Icon aria-hidden="true" className="animate-spin" />
          ) : (
            <FileSpreadsheetIcon aria-hidden="true" />
          )}
          {text.button[format]}
        </Button>
      ))}
      <span role="status" aria-live="polite" className="text-xs text-muted-foreground">
        {exportId === null ? null : current.isError ? (
          text.pollFailed
        ) : item?.state === 'ready' && item.media_id ? (
          <a
            href={exportDownloadUrl(item.media_id)}
            className="inline-flex items-center gap-1 text-sm text-primary underline-offset-2 hover:underline"
          >
            <DownloadIcon aria-hidden="true" className="size-4" />
            {fill(text.download, { name: item.file_name ?? '', rows: item.row_count ?? 0 })}
          </a>
        ) : item?.state === 'failed' ? (
          (text.failed[item.error ?? 'failed'] ?? text.failed.failed)
        ) : (
          text.preparing
        )}
      </span>
    </div>
  )
}

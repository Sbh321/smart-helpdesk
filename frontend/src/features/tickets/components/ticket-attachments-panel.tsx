import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import {
  AttachmentUploader,
  linkTicketMedia,
  MediaPicker,
  mediaQueries,
  unlinkTicketMedia,
} from '@/features/media'
import { apiUrl } from '@/lib/api/client'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'

export function TicketAttachmentsPanel({ tenantId, ticketId }: { tenantId: string; ticketId: string }) {
  const client = useQueryClient()
  const canUpdate = useCan('tickets.update')
  const canUpload = useCan('media.upload')
  const canPickMedia = useCan('media.view')
  const attachments = useQuery({
    ...mediaQueries.ticketAttachments(tenantId, ticketId),
    enabled: tenantId !== '',
  })
  const link = useMutation({
    mutationFn: (mediaId: string) => linkTicketMedia(ticketId, [mediaId]),
    onSuccess: () =>
      void client.invalidateQueries({ queryKey: queryKeys.tickets.attachments(tenantId, ticketId) }),
  })
  const unlink = useMutation({
    mutationFn: (mediaId: string) => unlinkTicketMedia(ticketId, mediaId),
    onSuccess: () =>
      void client.invalidateQueries({ queryKey: queryKeys.tickets.attachments(tenantId, ticketId) }),
  })

  return (
    <section className="space-y-4" aria-label={copy.tickets.detail.attachments}>
      {attachments.isPending ? (
        <Skeleton className="h-20 w-full" />
      ) : attachments.isError ? (
        <ErrorState error={attachments.error} onRetry={() => void attachments.refetch()} />
      ) : attachments.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{copy.media.noAttachments}</p>
      ) : (
        <ul className="space-y-2">
          {attachments.data.map((item) => (
            <li key={item.id} className="flex items-center gap-3 rounded-lg border p-3 text-sm">
              <a
                className="min-w-0 flex-1 truncate text-primary underline"
                href={apiUrl(`/v1/media/${item.id}/download`)}
              >
                {item.name}
              </a>
              <span className="text-muted-foreground">
                {formatFileSize(item.size_bytes, copy.media.units)}
              </span>
              {canUpdate ? (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={unlink.isPending}
                  aria-label={fill(copy.media.unlinkNamed, { name: item.name })}
                  onClick={() => unlink.mutate(item.id)}
                >
                  {copy.media.unlink}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      {canUpdate && canUpload && canPickMedia ? (
        <AttachmentUploader onUploaded={(id) => link.mutate(id)} />
      ) : null}
      {canUpdate && canPickMedia ? <MediaPicker onPick={(item) => link.mutate(item.id)} /> : null}
      {link.error ? <FormErrorBanner title={copy.media.linkFailed} error={link.error} /> : null}
      {unlink.error ? <FormErrorBanner title={copy.media.unlinkFailed} error={unlink.error} /> : null}
    </section>
  )
}

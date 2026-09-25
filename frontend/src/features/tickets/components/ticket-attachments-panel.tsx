import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DownloadIcon, UnlinkIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Lightbox } from '@/components/shared/lightbox'
import { Button, buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import {
  AttachmentUploader,
  linkTicketMedia,
  MEDIA_KIND_ICONS,
  type MediaItem,
  mediaKind,
  mediaLightboxItem,
  mediaQueries,
  mediaThumbUrl,
  mediaTypeLabel,
  mediaUrls,
  unlinkTicketMedia,
} from '@/features/media'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatFileSize } from '@/lib/format/file-size'

/**
 * The Ticket's attachments: tiles that open the lightbox over all of them, download and unlink, and the
 * upload field, whose uploads and library picks are linked to the Ticket at once.
 */
export function TicketAttachmentsPanel({ tenantId, ticketId }: { tenantId: string; ticketId: string }) {
  const client = useQueryClient()
  const timeZone = useSession().session?.tenant.timezone ?? 'UTC'
  const canUpdate = useCan('tickets.update')
  const canPickMedia = useCan('media.view')
  const [previewing, setPreviewing] = useState<number | null>(null)
  const attachments = useQuery({
    ...mediaQueries.ticketAttachments(tenantId, ticketId),
    enabled: tenantId !== '',
  })
  const refresh = () =>
    void client.invalidateQueries({ queryKey: queryKeys.tickets.attachments(tenantId, ticketId) })
  const link = useMutation({
    mutationFn: (mediaIds: string[]) => linkTicketMedia(ticketId, mediaIds),
    onSuccess: refresh,
  })
  const unlink = useMutation({
    mutationFn: (mediaId: string) => unlinkTicketMedia(ticketId, mediaId),
    onSuccess: refresh,
  })
  const items = attachments.data
  const lightboxItems = useMemo(
    () => (items ?? []).map((item) => mediaLightboxItem(item, timeZone)),
    [items, timeZone],
  )

  return (
    <section className="space-y-4" aria-label={copy.tickets.detail.attachments}>
      {attachments.isPending ? (
        <Skeleton className="h-20 w-full" />
      ) : attachments.isError ? (
        <ErrorState error={attachments.error} onRetry={() => void attachments.refetch()} />
      ) : attachments.data.length === 0 ? (
        <p className="text-muted-foreground text-sm">{copy.media.noAttachments}</p>
      ) : (
        <ul className="grid gap-2" aria-label={copy.tickets.detail.attachments}>
          {attachments.data.map((item, index) => (
            <AttachmentTile
              key={item.id}
              item={item}
              onPreview={() => setPreviewing(index)}
              onUnlink={canUpdate ? () => unlink.mutate(item.id) : undefined}
              unlinking={unlink.isPending}
            />
          ))}
        </ul>
      )}
      {canUpdate && canPickMedia ? (
        <AttachmentUploader
          library
          excludeIds={items?.map((item) => item.id)}
          onUploaded={(id) => link.mutate([id])}
          onPicked={(picked) => link.mutate(picked.map((item) => item.id))}
        />
      ) : null}
      {link.error ? <FormErrorBanner title={copy.media.linkFailed} error={link.error} /> : null}
      {unlink.error ? <FormErrorBanner title={copy.media.unlinkFailed} error={unlink.error} /> : null}
      <Lightbox
        items={lightboxItems}
        index={previewing}
        onIndexChange={setPreviewing}
        onClose={() => setPreviewing(null)}
      />
    </section>
  )
}

function AttachmentTile({
  item,
  onPreview,
  onUnlink,
  unlinking,
}: {
  item: MediaItem
  onPreview: () => void
  onUnlink?: () => void
  unlinking: boolean
}) {
  const Icon = MEDIA_KIND_ICONS[mediaKind(item.mime_type)]
  const thumb = mediaThumbUrl(item)
  return (
    <li className="flex items-center gap-3 rounded-lg border border-border bg-surface p-2 text-sm">
      <button
        type="button"
        onClick={onPreview}
        className="flex min-w-0 flex-1 cursor-zoom-in items-center gap-3 rounded-control text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
      >
        <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-control bg-muted text-muted-foreground">
          {thumb ? (
            <img src={thumb} alt="" loading="lazy" className="size-full object-cover" />
          ) : (
            <Icon aria-hidden="true" className="size-5" />
          )}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium text-primary">{item.name}</span>
          <span className="block truncate text-muted-foreground text-xs">
            {mediaTypeLabel(item.mime_type, item.name)} · {formatFileSize(item.size_bytes, copy.media.units)}
          </span>
        </span>
      </button>
      <Hint label={fill(copy.media.downloadNamed, { name: item.name })}>
        <a
          href={mediaUrls(item.id).download}
          download={item.name}
          aria-label={fill(copy.media.downloadNamed, { name: item.name })}
          className={buttonVariants({ variant: 'ghost', size: 'icon-sm' })}
        >
          <DownloadIcon aria-hidden="true" />
        </a>
      </Hint>
      {onUnlink ? (
        <Hint label={fill(copy.media.unlinkNamed, { name: item.name })}>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            disabled={unlinking}
            aria-label={fill(copy.media.unlinkNamed, { name: item.name })}
            onClick={onUnlink}
          >
            <UnlinkIcon aria-hidden="true" />
          </Button>
        </Hint>
      ) : null}
    </li>
  )
}

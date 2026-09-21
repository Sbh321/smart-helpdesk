import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { RotateCwIcon, SendIcon, TriangleAlertIcon } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import {
  type DeliveryState,
  retryWebhookDelivery,
  type Webhook,
  type WebhookDelivery,
  webhookQueries,
} from '../api/webhook-queries'

const text = copy.webhooks.deliveries

// Failed and dead carry an icon rather than the destructive badge colours: the pale red badge loses
// contrast on a hovered row.
const stateVariant: Record<DeliveryState, 'secondary' | 'outline'> = {
  pending: 'outline',
  succeeded: 'secondary',
  failed: 'outline',
  dead: 'outline',
}

function errorText(error: unknown, fallback: string): string {
  return isApiError(error) ? (error.detail ?? error.title) : fallback
}

function DeliveryRow({
  delivery,
  canRetry,
  timeZone,
  onRetry,
  retrying,
}: {
  delivery: WebhookDelivery
  canRetry: boolean
  timeZone: string
  onRetry: (delivery: WebhookDelivery) => void
  retrying: boolean
}) {
  const retryable = canRetry && (delivery.state === 'failed' || delivery.state === 'dead')
  const response = delivery.response_status !== null ? String(delivery.response_status) : null

  return (
    <TableRow>
      <TableCell className="font-mono text-xs">{delivery.event_type}</TableCell>
      <TableCell>
        <Badge variant={stateVariant[delivery.state]}>
          {delivery.state === 'failed' || delivery.state === 'dead' ? (
            <TriangleAlertIcon aria-hidden="true" className="text-destructive" />
          ) : null}
          {text.states[delivery.state]}
        </Badge>
      </TableCell>
      <TableCell className="tabular-nums">{delivery.attempt}</TableCell>
      <TableCell>
        <span className="font-mono text-xs">{response ?? text.noResponse}</span>
        {delivery.error ? (
          <span className="block text-xs text-muted-foreground">{delivery.error}</span>
        ) : null}
      </TableCell>
      <TableCell>
        {delivery.last_attempted_at ? formatInZone(delivery.last_attempted_at, timeZone) : '—'}
      </TableCell>
      <TableCell>
        {delivery.next_attempt_at && delivery.state !== 'succeeded' && delivery.state !== 'dead'
          ? formatInZone(delivery.next_attempt_at, timeZone)
          : '—'}
      </TableCell>
      <TableCell className="text-right">
        {retryable ? (
          <Button
            size="sm"
            variant="outline"
            disabled={retrying}
            aria-label={fill(text.retryNamed, { event: delivery.event_type })}
            onClick={() => onRetry(delivery)}
          >
            <RotateCwIcon aria-hidden="true" />
            {text.retry}
          </Button>
        ) : null}
      </TableCell>
    </TableRow>
  )
}

/**
 * The delivery log of one subscription (docs/07-api/webhooks.md §Delivery): a cursor feed, newest
 * first, with manual retry of failed and dead deliveries while the webhook is enabled.
 */
export function WebhookDeliveries({ webhook, onClose }: { webhook: Webhook; onClose: () => void }) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const queryClient = useQueryClient()
  const deliveries = useInfiniteQuery(webhookQueries.deliveries(tenantId, webhook.id))
  const retry = useMutation({
    mutationFn: (delivery: WebhookDelivery) => retryWebhookDelivery(delivery.id),
    onSuccess: async () => {
      toast.success(text.retried)
      await queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.deliveries(tenantId, webhook.id) })
    },
    onError: (error) => toast.error(errorText(error, text.retryFailed)),
  })
  const rows = deliveries.data?.pages.flatMap((page) => page.data) ?? []
  const headingId = `webhook-deliveries-${webhook.id}`

  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id={headingId} className="text-base font-semibold">
            {fill(text.title, { name: webhook.name })}
          </h3>
          <p className="max-w-2xl text-sm text-muted-foreground">{text.intro}</p>
        </div>
        <Button size="sm" variant="ghost" onClick={onClose}>
          {text.close}
        </Button>
      </div>
      {deliveries.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : deliveries.isError ? (
        <ErrorState error={deliveries.error} onRetry={() => void deliveries.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState icon={SendIcon} title={text.empty} />
      ) : (
        <>
          <Table aria-label={fill(text.label, { name: webhook.name })}>
            <TableHeader>
              <TableRow>
                <TableHead>{text.columns.event}</TableHead>
                <TableHead>{text.columns.state}</TableHead>
                <TableHead>{text.columns.attempts}</TableHead>
                <TableHead>{text.columns.response}</TableHead>
                <TableHead>{text.columns.lastAttempt}</TableHead>
                <TableHead>{text.columns.nextAttempt}</TableHead>
                <TableHead className="text-right">{text.columns.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((delivery) => (
                <DeliveryRow
                  key={delivery.id}
                  delivery={delivery}
                  canRetry={webhook.is_active}
                  timeZone={timeZone}
                  retrying={retry.isPending}
                  onRetry={(item) => retry.mutate(item)}
                />
              ))}
            </TableBody>
          </Table>
          {deliveries.hasNextPage ? (
            <Button
              variant="outline"
              size="sm"
              disabled={deliveries.isFetchingNextPage}
              onClick={() => void deliveries.fetchNextPage()}
            >
              {deliveries.isFetchingNextPage ? text.loadingMore : text.loadMore}
            </Button>
          ) : null}
        </>
      )}
    </section>
  )
}

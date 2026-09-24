import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { LucideIcon } from 'lucide-react'
import {
  CircleCheckIcon,
  ClockIcon,
  OctagonXIcon,
  RotateCwIcon,
  SendIcon,
  TriangleAlertIcon,
} from 'lucide-react'
import { useState } from 'react'
import { CodeBlock } from '@/components/shared/code-block'
import { SelectFilter } from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
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
import { canRetryDelivery, type DeliveryStatus, deliveryErrorText, deliveryStatus } from '../delivery-status'
import { CopyableValue } from './copyable-value'

const text = copy.webhooks.deliveries
const DELIVERY_STATES: readonly DeliveryState[] = ['pending', 'succeeded', 'failed', 'dead']

/** Each status has its own icon shape and word; colour only repeats them (greyscale-safe). */
const STATUS: Record<DeliveryStatus, { icon: LucideIcon; tone: string }> = {
  queued: { icon: ClockIcon, tone: 'text-muted-foreground' },
  delivered: { icon: CircleCheckIcon, tone: 'text-success' },
  retrying: { icon: RotateCwIcon, tone: 'text-warning' },
  failed: { icon: TriangleAlertIcon, tone: 'text-destructive' },
  gave_up: { icon: OctagonXIcon, tone: 'text-destructive' },
}

function errorText(error: unknown, fallback: string): string {
  return isApiError(error) ? (error.detail ?? error.title) : fallback
}

function DeliveryStatusLabel({ delivery }: { delivery: Pick<WebhookDelivery, 'state' | 'next_attempt_at'> }) {
  const status = deliveryStatus(delivery)
  const { icon: Icon, tone } = STATUS[status]
  return (
    <span className={`inline-flex items-center gap-1.5 font-medium ${tone}`}>
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {text.status[status]}
    </span>
  )
}

function useRetry(tenantId: string, webhookId: string, onDone?: () => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (delivery: WebhookDelivery) => retryWebhookDelivery(delivery.id),
    onSuccess: async (_, delivery) => {
      toast.success(text.retried)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.deliveries(tenantId, webhookId) }),
        queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.delivery(tenantId, delivery.id) }),
      ])
      onDone?.()
    },
    onError: (error) => toast.error(errorText(error, text.retryFailed)),
  })
}

/**
 * One delivery in full (M4-11): what happens next, what the endpoint answered and in how long, the
 * error in words, the response excerpt and the payload as readable JSON with copy. Enough to diagnose a
 * failed delivery without the database. The API never returns the signing secret or request headers,
 * so neither can appear here.
 */
function DeliveryDetail({
  webhook,
  delivery,
  onClose,
}: {
  webhook: Webhook
  delivery: WebhookDelivery | null
  onClose: () => void
}) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const detail = useQuery({
    ...webhookQueries.delivery(tenantId, delivery?.id ?? ''),
    enabled: delivery !== null && tenantId !== '',
  })
  const retry = useRetry(tenantId, webhook.id)
  const at = (iso: string | null) => (iso ? formatInZone(iso, timeZone, 'd MMM yyyy, HH:mm:ss') : '—')

  return (
    <Dialog open={delivery !== null} onOpenChange={(open) => (open ? undefined : onClose())}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{fill(text.detailTitle, { event: delivery?.event_type ?? '' })}</DialogTitle>
          <DialogDescription>{text.detailDescription}</DialogDescription>
        </DialogHeader>
        {!delivery ? null : detail.isPending ? (
          <Skeleton className="h-64 w-full" />
        ) : detail.isError ? (
          <ErrorState title={text.detailFailed} error={detail.error} onRetry={() => void detail.refetch()} />
        ) : (
          <div className="flex min-w-0 flex-col gap-4">
            <dl className="grid grid-cols-[max-content_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm">
              <dt className="text-muted-foreground">{text.fields.status}</dt>
              <dd>
                <DeliveryStatusLabel delivery={detail.data} />
              </dd>
              {deliveryErrorText(detail.data.error, detail.data.response_status) ? (
                <>
                  <dt className="text-muted-foreground">{text.fields.error}</dt>
                  <dd>{deliveryErrorText(detail.data.error, detail.data.response_status)}</dd>
                </>
              ) : null}
              <dt className="text-muted-foreground">{text.fields.response}</dt>
              <dd className="font-mono">{detail.data.response_status ?? text.noResponse}</dd>
              <dt className="text-muted-foreground">{text.fields.duration}</dt>
              <dd className="tabular-nums">
                {detail.data.duration_ms === null
                  ? '—'
                  : fill(text.duration, { ms: detail.data.duration_ms })}
              </dd>
              <dt className="text-muted-foreground">{text.fields.attempts}</dt>
              <dd className="tabular-nums">
                {fill(text.fields.attemptsValue, {
                  attempt: detail.data.attempt,
                  manual: detail.data.manual_retries,
                })}
              </dd>
              <dt className="text-muted-foreground">{text.fields.lastAttempt}</dt>
              <dd>{at(detail.data.last_attempted_at)}</dd>
              <dt className="text-muted-foreground">{text.fields.nextAttempt}</dt>
              <dd>
                {deliveryStatus(detail.data) === 'retrying' || deliveryStatus(detail.data) === 'queued'
                  ? at(detail.data.next_attempt_at)
                  : text.noNextAttempt}
              </dd>
              <dt className="text-muted-foreground">{text.fields.created}</dt>
              <dd>{at(detail.data.created_at)}</dd>
            </dl>
            <div className="grid gap-3 sm:grid-cols-2">
              <CopyableValue id="delivery-id" label={text.fields.deliveryId} value={detail.data.id} />
              <CopyableValue
                id="delivery-event-id"
                label={text.fields.eventId}
                value={detail.data.event_id}
              />
            </div>
            {detail.data.response_excerpt ? (
              <CodeBlock
                label={text.responseExcerpt}
                value={detail.data.response_excerpt}
                maxHeight="max-h-40"
              />
            ) : null}
            <CodeBlock label={text.payload} value={detail.data.payload} />
            {canRetryDelivery(detail.data, webhook.is_active) ? (
              <div>
                <Button
                  variant="outline"
                  disabled={retry.isPending}
                  onClick={() => retry.mutate(detail.data)}
                >
                  <RotateCwIcon aria-hidden="true" />
                  {text.retry}
                </Button>
              </div>
            ) : null}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

/**
 * The delivery log of one subscription (docs/07-api/webhooks.md §Delivery; roadmap M4-11): a cursor
 * feed, newest first, filterable by status for long histories. Each row says what the delivery is
 * doing in words (queued, delivered, retrying with the next try, failed, gave up), the response code
 * and duration; "Details" opens the payload and the diagnosis, and failed or dead deliveries of an
 * enabled webhook can be retried.
 */
export function WebhookDeliveries({ webhook, onClose }: { webhook: Webhook; onClose: () => void }) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const [state, setState] = useState<DeliveryState | undefined>(undefined)
  const [open, setOpen] = useState<WebhookDelivery | null>(null)
  const deliveries = useInfiniteQuery(webhookQueries.deliveries(tenantId, webhook.id, state))
  const retry = useRetry(tenantId, webhook.id)
  const rows = deliveries.data?.pages.flatMap((page) => page.data) ?? []
  const headingId = `webhook-deliveries-${webhook.id}`
  const at = (iso: string) => formatInZone(iso, timeZone, 'd MMM, HH:mm:ss')

  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 id={headingId} className="text-base font-semibold">
            {fill(text.title, { name: webhook.name })}
          </h3>
          <p className="max-w-2xl text-sm text-muted-foreground">{text.intro}</p>
        </div>
        <div className="flex items-center gap-2">
          <SelectFilter
            label={text.statusFilter}
            value={state}
            defaultValue="all"
            options={[
              { value: 'all', label: text.allStatuses },
              ...DELIVERY_STATES.map((value) => ({ value, label: text.stateFilter[value] })),
            ]}
            onChange={(value) => setState(value && value !== 'all' ? (value as DeliveryState) : undefined)}
          />
          <Button size="sm" variant="ghost" onClick={onClose}>
            {text.close}
          </Button>
        </div>
      </div>
      {deliveries.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : deliveries.isError ? (
        <ErrorState error={deliveries.error} onRetry={() => void deliveries.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState icon={SendIcon} title={state ? text.filterEmpty : text.empty} />
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
                <TableHead className="text-right">{text.columns.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((delivery) => {
                const status = deliveryStatus(delivery)
                return (
                  <TableRow key={delivery.id}>
                    <TableCell className="font-mono text-xs">{delivery.event_type}</TableCell>
                    <TableCell>
                      <DeliveryStatusLabel delivery={delivery} />
                      {status === 'retrying' && delivery.next_attempt_at ? (
                        <span className="block text-xs text-muted-foreground">
                          {fill(text.retryAt, { time: at(delivery.next_attempt_at) })}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell className="tabular-nums">{delivery.attempt}</TableCell>
                    <TableCell>
                      <span className="font-mono text-xs">{delivery.response_status ?? text.noResponse}</span>
                      {delivery.duration_ms !== null ? (
                        <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                          {fill(text.duration, { ms: delivery.duration_ms })}
                        </span>
                      ) : null}
                      {deliveryErrorText(delivery.error, delivery.response_status) ? (
                        <span className="block max-w-xs text-xs text-muted-foreground">
                          {deliveryErrorText(delivery.error, delivery.response_status)}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell className="tabular-nums">
                      {delivery.last_attempted_at ? at(delivery.last_attempted_at) : '—'}
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-1">
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={fill(text.detailsNamed, {
                            event: delivery.event_type,
                            time: at(delivery.created_at),
                          })}
                          onClick={() => setOpen(delivery)}
                        >
                          {text.details}
                        </Button>
                        {canRetryDelivery(delivery, webhook.is_active) ? (
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={retry.isPending}
                            aria-label={fill(text.retryNamed, { event: delivery.event_type })}
                            onClick={() => retry.mutate(delivery)}
                          >
                            <RotateCwIcon aria-hidden="true" />
                            {text.retry}
                          </Button>
                        ) : null}
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })}
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
      <DeliveryDetail webhook={webhook} delivery={open} onClose={() => setOpen(null)} />
    </section>
  )
}

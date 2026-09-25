import { useQueryClient } from '@tanstack/react-query'
import { ExternalLinkIcon, FileTextIcon, ImageIcon } from 'lucide-react'
import { type ReactNode, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Lightbox, type LightboxItem } from '@/components/shared/lightbox'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button, buttonVariants } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { formatInZone } from '@/lib/datetime/format'
import { formatMoney } from '@/lib/format/money'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type Payment, platform, receiptUrl } from '../api'
import { PaymentStatusBadge } from './payment-status'

const text = copy.platform.payments

/**
 * One payment and its receipt, and the decision (ADR-0025 §3): approve (the subscription moves on at
 * once) or reject with a reason the workspace reads. A decided payment is shown read-only.
 */
export function PaymentReviewDialog({ payment, onClose }: { payment: Payment | null; onClose: () => void }) {
  return (
    <Dialog
      open={payment !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-3xl">
        {payment ? <Review payment={payment} onClose={onClose} /> : null}
      </DialogContent>
    </Dialog>
  )
}

function Review({ payment, onClose }: { payment: Payment; onClose: () => void }) {
  const client = useQueryClient()
  const server = useServerErrors()
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [viewing, setViewing] = useState(false)
  const name = payment.workspace?.name ?? payment.plan.name
  const money = (minor: number) => formatMoney(minor, payment.currency)
  const receipt = payment.receipt
  const isImage = receipt?.mime_type.startsWith('image/') ?? false
  const lightbox: LightboxItem[] = receipt
    ? [
        {
          id: receipt.id,
          name: receipt.name,
          kind: isImage ? 'image' : receipt.mime_type === 'application/pdf' ? 'pdf' : 'file',
          summary: `${fill(text.reviewTitle, { name })}`,
          sourceUrl: receiptUrl(payment.id),
          openUrl: receiptUrl(payment.id),
        },
      ]
    : []

  const decide = async (action: 'approve' | 'reject') => {
    server.reset()
    setBusy(true)
    try {
      const decided =
        action === 'approve'
          ? await platform.approvePayment(payment.id)
          : await platform.rejectPayment(payment.id, reason.trim())
      await client.invalidateQueries({ queryKey: queryKeys.platform.all })
      toast.success(
        action === 'approve'
          ? fill(text.approved, {
              name,
              date: decided.period_ends_at ? formatInZone(decided.period_ends_at, 'UTC', 'd MMM yyyy') : '',
            })
          : text.rejected,
      )
      onClose()
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  const row = (label: string, value: ReactNode) => (
    <div className="grid grid-cols-[9rem_minmax(0,1fr)] gap-3 py-1.5">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 break-words">{value}</dd>
    </div>
  )

  return (
    <>
      <DialogHeader>
        <DialogTitle>{fill(text.reviewTitle, { name })}</DialogTitle>
        <DialogDescription>
          <PaymentStatusBadge status={payment.status} />
        </DialogDescription>
      </DialogHeader>
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <div className="grid gap-6 md:grid-cols-[minmax(0,1fr)_16rem]">
        <dl className="divide-y divide-border text-sm">
          {row(text.fields.plan, fill(text.periods, { plan: payment.plan.name, count: payment.periods }))}
          {row(
            text.fields.amount,
            <span className="font-semibold tabular-nums">{money(payment.amount_minor)}</span>,
          )}
          {row(
            text.fields.expected,
            <span
              className={
                payment.amount_minor === payment.expected_minor
                  ? 'tabular-nums'
                  : 'font-medium text-warning tabular-nums'
              }
            >
              {money(payment.expected_minor)}
            </span>,
          )}
          {row(text.fields.paidOn, formatInZone(`${payment.paid_on}T12:00:00Z`, 'UTC', 'd MMM yyyy'))}
          {row(text.fields.method, copy.platform.methods[payment.method])}
          {payment.reference
            ? row(text.fields.reference, <span className="font-mono">{payment.reference}</span>)
            : null}
          {payment.note ? row(text.fields.note, payment.note) : null}
          {row(
            text.fields.sentBy,
            payment.submitted_by
              ? `${payment.submitted_by.name} (${payment.submitted_by.email})`
              : text.recordedByAdmin,
          )}
          {payment.created_at
            ? row(text.fields.sentAt, formatInZone(payment.created_at, 'UTC', 'd MMM yyyy, HH:mm'))
            : null}
          {payment.period_starts_at && payment.period_ends_at
            ? row(
                text.fields.period,
                `${formatInZone(payment.period_starts_at, 'UTC', 'd MMM yyyy')} – ${formatInZone(payment.period_ends_at, 'UTC', 'd MMM yyyy')}`,
              )
            : null}
          {payment.rejection_reason ? row(text.fields.reason, payment.rejection_reason) : null}
        </dl>
        <section aria-label={text.receipt} className="flex flex-col gap-2">
          <h3 className="font-medium text-sm">{text.receipt}</h3>
          {receipt ? (
            <>
              <button
                type="button"
                onClick={() => setViewing(true)}
                aria-label={fill(copy.media.previewNamed, { name: receipt.name })}
                className="flex aspect-3/4 w-full cursor-zoom-in items-center justify-center overflow-hidden rounded-card border border-border bg-muted outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
              >
                {isImage ? (
                  <img src={receiptUrl(payment.id)} alt="" className="size-full object-contain" />
                ) : (
                  <span className="flex flex-col items-center gap-2 text-muted-foreground">
                    {receipt.mime_type === 'application/pdf' ? (
                      <FileTextIcon aria-hidden="true" className="size-10" />
                    ) : (
                      <ImageIcon aria-hidden="true" className="size-10" />
                    )}
                    <span className="px-2 text-center text-xs">{receipt.name}</span>
                  </span>
                )}
              </button>
              <a
                href={receiptUrl(payment.id)}
                target="_blank"
                rel="noopener noreferrer"
                className={buttonVariants({ variant: 'outline', size: 'sm' })}
              >
                <ExternalLinkIcon aria-hidden="true" />
                {text.openReceipt}
              </a>
            </>
          ) : (
            <p className="text-muted-foreground text-sm">{text.noReceipt}</p>
          )}
        </section>
      </div>
      {payment.status === 'pending' && rejecting ? (
        <TextareaField
          id="reject-reason"
          label={text.rejectReason}
          description={text.rejectReasonHint}
          value={reason}
          onValueChange={setReason}
          errors={server.fields.reason}
          autoFocus
        />
      ) : null}
      {payment.status === 'pending' ? (
        <DialogFooter className="items-center sm:justify-between">
          <p className="text-muted-foreground text-xs">
            {fill(text.approveHint, { count: payment.periods, months: payment.plan.period_months ?? 1 })}
          </p>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            {rejecting ? (
              <>
                <Button type="button" variant="outline" disabled={busy} onClick={() => setRejecting(false)}>
                  {copy.media.cancel}
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  disabled={busy}
                  onClick={() => void decide('reject')}
                >
                  {text.reject}
                </Button>
              </>
            ) : (
              <>
                <Button type="button" variant="outline" disabled={busy} onClick={() => setRejecting(true)}>
                  {text.reject}
                </Button>
                <Button type="button" disabled={busy} onClick={() => void decide('approve')}>
                  {text.approve}
                </Button>
              </>
            )}
          </div>
        </DialogFooter>
      ) : null}
      <Lightbox
        items={lightbox}
        index={viewing ? 0 : null}
        onIndexChange={() => undefined}
        onClose={() => setViewing(false)}
      />
    </>
  )
}

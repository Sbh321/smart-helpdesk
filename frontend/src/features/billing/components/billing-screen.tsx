import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  CircleCheckIcon,
  CircleDashedIcon,
  CircleXIcon,
  ClockIcon,
  HourglassIcon,
  LockIcon,
  type LucideIcon,
  SparklesIcon,
} from 'lucide-react'
import { type FormEvent, useMemo, useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Lightbox } from '@/components/shared/lightbox'
import { SelectField } from '@/components/shared/select-field'
import { SettingsPage } from '@/components/shared/settings-page'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { AttachmentUploader, type AttachmentUploaderState, mediaLightboxItem } from '@/features/media'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { formatMoney, fromMinor, toMinor } from '@/lib/format/money'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { cn } from '@/lib/utils'
import {
  type BillingPayment,
  type BillingPlan,
  billingQuery,
  type PaymentInput,
  submitPayment,
  type WorkspaceSubscription,
} from '../api'

const text = copy.billing
const METHODS: PaymentInput['method'][] = ['bank_transfer', 'wallet', 'cash', 'other']
const STATE_LOOK: Record<WorkspaceSubscription['state'], { icon: LucideIcon; tone: string }> = {
  trialing: { icon: SparklesIcon, tone: 'text-info' },
  active: { icon: CircleCheckIcon, tone: 'text-success' },
  grace: { icon: HourglassIcon, tone: 'text-warning' },
  expired: { icon: LockIcon, tone: 'text-destructive' },
  none: { icon: CircleDashedIcon, tone: 'text-muted-foreground' },
}
const PAYMENT_LOOK = {
  pending: { icon: ClockIcon, tone: 'text-warning' },
  approved: { icon: CircleCheckIcon, tone: 'text-success' },
  rejected: { icon: CircleXIcon, tone: 'text-destructive' },
} as const

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * The workspace's Billing page (ADR-0025 §3, §6): its plan and state, where to pay, a payment with its
 * receipt for review, and the payments sent. Owners and admins (`billing.manage`).
 */
export function BillingScreen() {
  const canManage = useCan('billing.manage')
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const zone = session?.tenant.timezone ?? 'UTC'
  const billing = useQuery({ ...billingQuery(tenantId), enabled: canManage && tenantId !== '' })

  if (!canManage) return <ForbiddenState />

  return (
    <SettingsPage title={text.title} description={text.description}>
      {billing.isPending ? (
        <div className="flex flex-col gap-4" aria-busy="true">
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : billing.isError ? (
        <ErrorState error={billing.error} onRetry={() => void billing.refetch()} />
      ) : (
        <div className="flex flex-col gap-6">
          <CurrentPlan subscription={billing.data.subscription} zone={zone} />
          <section
            aria-labelledby="billing-how"
            className="flex flex-col gap-2 rounded-card border border-border bg-surface p-4 shadow-1"
          >
            <h2 id="billing-how" className="font-semibold text-base">
              {text.howToPay}
            </h2>
            <p className="whitespace-pre-line text-sm">
              {billing.data.payment_instructions || text.noInstructions}
            </p>
          </section>
          <PaymentForm tenantId={tenantId} plans={billing.data.plans} />
          <History payments={billing.data.payments} zone={zone} />
        </div>
      )}
    </SettingsPage>
  )
}

function CurrentPlan({ subscription, zone }: { subscription: WorkspaceSubscription; zone: string }) {
  const { icon: Icon, tone } = STATE_LOOK[subscription.state]
  const date = (value: string | null | undefined) => (value ? formatInZone(value, zone, 'd MMMM yyyy') : '')
  const line = {
    trialing: fill(text.trialEnds, { date: date(subscription.ends_at) }),
    active: fill(text.paidUntil, { date: date(subscription.ends_at) }),
    grace: fill(text.graceUntil, {
      date: date(subscription.ends_at),
      grace: date(subscription.grace_ends_at),
    }),
    expired: fill(text.readOnlySince, { date: date(subscription.grace_ends_at) }),
    none: text.notBilled,
  }[subscription.state]
  const days = subscription.days_left

  return (
    <section
      aria-labelledby="billing-current"
      className="flex flex-wrap items-start gap-4 rounded-card border border-border bg-surface p-5 shadow-1"
    >
      <span className={cn('grid size-11 shrink-0 place-items-center rounded-full bg-muted', tone)}>
        <Icon aria-hidden="true" className="size-5" />
      </span>
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <h2 id="billing-current" className="text-muted-foreground text-sm">
          {text.current}
        </h2>
        <p className="font-semibold text-lg">
          {subscription.plan?.name ?? text.states.none}
          <span className={cn('ms-2 font-medium text-sm', tone)}>{text.states[subscription.state]}</span>
        </p>
        <p className="text-sm">{line}</p>
      </div>
      {days !== null && days !== undefined ? (
        <p className="font-semibold text-2xl tabular-nums">
          {days === 1 ? text.oneDayLeft : fill(text.daysLeft, { count: days })}
        </p>
      ) : null}
    </section>
  )
}

function PaymentForm({ tenantId, plans }: { tenantId: string; plans: BillingPlan[] }) {
  const client = useQueryClient()
  const server = useServerErrors()
  const [planId, setPlanId] = useState<string | null>(plans[0]?.id ?? null)
  const plan = plans.find((candidate) => candidate.id === planId) ?? plans[0] ?? null
  const [periods, setPeriods] = useState('1')
  const [amount, setAmount] = useState<string | null>(null)
  const [paidOn, setPaidOn] = useState(today)
  const [method, setMethod] = useState<PaymentInput['method']>('bank_transfer')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [receipt, setReceipt] = useState<AttachmentUploaderState>({ mediaIds: [], uploading: false })
  const [cycle, setCycle] = useState(0)
  const [busy, setBusy] = useState(false)
  const expected = plan ? plan.price_minor * (Number(periods) || 1) : 0
  const shownAmount = amount ?? (plan ? fromMinor(expected) : '')
  const months = plan?.period_months ?? 1

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    const receiptId = receipt.mediaIds[0]
    if (!plan || !receiptId) {
      server.capture(new Error(text.receiptMissing))
      return
    }
    setBusy(true)
    try {
      await submitPayment({
        plan_id: plan.id,
        periods: Number(periods) || 0,
        amount_minor: toMinor(shownAmount) ?? 0,
        paid_on: paidOn,
        method,
        reference: reference.trim() || undefined,
        note: note.trim() || undefined,
        receipt_media_id: receiptId,
      })
      await client.invalidateQueries({ queryKey: billingQuery(tenantId).queryKey })
      await client.invalidateQueries({ queryKey: queryKeys.media.all(tenantId) })
      toast.success(text.sent)
      setAmount(null)
      setReference('')
      setNote('')
      setPeriods('1')
      setCycle((value) => value + 1)
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  if (plans.length === 0) return null

  return (
    <section
      aria-labelledby="billing-pay"
      className="flex flex-col gap-4 rounded-card border border-border bg-surface p-5 shadow-1"
    >
      <div className="flex flex-col gap-1">
        <h2 id="billing-pay" className="font-semibold text-base">
          {text.pay}
        </h2>
        <p className="text-muted-foreground text-sm">{text.payDescription}</p>
      </div>
      <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-4">
        {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
        <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_10rem]">
          <SelectField
            id="billing-plan"
            label={text.plan}
            value={plan?.id ?? null}
            onValueChange={(value) => {
              setPlanId(String(value))
              setAmount(null)
            }}
            options={plans.map((candidate) => ({
              value: candidate.id,
              label: `${candidate.name} · ${formatMoney(candidate.price_minor, candidate.currency)}`,
            }))}
            errors={server.fields.plan_id}
          />
          <TextField
            id="billing-periods"
            label={text.periods}
            type="number"
            min={1}
            max={36}
            value={periods}
            onValueChange={(value) => {
              setPeriods(value)
              setAmount(null)
            }}
            description={fill(text.periodsHint, { months: months === 1 ? '1 month' : `${months} months` })}
            errors={server.fields.periods}
          />
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <TextField
            id="billing-amount"
            label={`${text.amount} (${plan?.currency ?? 'NPR'})`}
            inputMode="decimal"
            value={shownAmount}
            onValueChange={setAmount}
            description={
              plan ? fill(text.amountHint, { amount: formatMoney(expected, plan.currency) }) : undefined
            }
            errors={server.fields.amount_minor}
          />
          <TextField
            id="billing-paid-on"
            label={text.paidOn}
            type="date"
            value={paidOn}
            onValueChange={setPaidOn}
            errors={server.fields.paid_on}
          />
          <SelectField
            id="billing-method"
            label={text.method}
            value={method}
            onValueChange={(value) => setMethod(value as PaymentInput['method'])}
            options={METHODS.map((value) => ({ value, label: copy.platform.methods[value] }))}
            errors={server.fields.method}
          />
        </div>
        <TextField
          id="billing-reference"
          label={text.reference}
          description={text.referenceHint}
          value={reference}
          onValueChange={setReference}
          errors={server.fields.reference}
        />
        <TextareaField
          id="billing-note"
          label={text.note}
          value={note}
          onValueChange={setNote}
          errors={server.fields.note}
          rows={2}
        />
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 font-medium text-sm">{text.receipt}</legend>
          <p className="text-muted-foreground text-xs">{text.receiptHint}</p>
          <AttachmentUploader key={cycle} purpose="receipt" maxFiles={1} library onChange={setReceipt} />
          {server.fields.receipt_media_id ? (
            <p role="alert" className="text-destructive text-sm">
              {server.fields.receipt_media_id.join(' ')}
            </p>
          ) : null}
        </fieldset>
        <div>
          <Button type="submit" disabled={busy || receipt.uploading || receipt.mediaIds.length === 0}>
            {busy ? text.submitting : text.submit}
          </Button>
        </div>
      </form>
    </section>
  )
}

function History({ payments, zone }: { payments: BillingPayment[]; zone: string }) {
  const [previewing, setPreviewing] = useState<number | null>(null)
  const withReceipts = useMemo(() => payments.filter((payment) => payment.receipt), [payments])
  const lightbox = useMemo(
    () =>
      withReceipts.map((payment) => {
        const receipt = payment.receipt as NonNullable<BillingPayment['receipt']>
        return mediaLightboxItem({
          id: receipt.id,
          name: receipt.name,
          mime_type: receipt.mime_type,
          size_bytes: receipt.size_bytes,
          width: receipt.width,
          height: receipt.height,
          variants: {
            thumb: receipt.has_thumb ? { width: 0, height: 0 } : null,
            preview: receipt.has_preview ? { width: 0, height: 0 } : null,
          },
        })
      }),
    [withReceipts],
  )

  return (
    <section aria-labelledby="billing-history" className="flex flex-col gap-3">
      <h2 id="billing-history" className="font-semibold text-base">
        {text.history}
      </h2>
      {payments.length === 0 ? (
        <p className="text-muted-foreground text-sm">{text.noHistory}</p>
      ) : (
        <Table aria-label={text.historyLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{text.columns.sent}</TableHead>
              <TableHead>{text.columns.plan}</TableHead>
              <TableHead className="text-right">{text.columns.amount}</TableHead>
              <TableHead>{text.columns.status}</TableHead>
              <TableHead>{text.columns.receipt}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {payments.map((payment) => {
              const look = PAYMENT_LOOK[payment.status]
              const Icon = look.icon
              const receiptIndex = withReceipts.indexOf(payment)
              return (
                <TableRow key={payment.id}>
                  <TableCell className="tabular-nums">
                    {payment.created_at ? formatInZone(payment.created_at, zone, 'd MMM yyyy') : ''}
                  </TableCell>
                  <TableCell>
                    {fill(text.periodsOf, { plan: payment.plan.name, count: payment.periods })}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatMoney(payment.amount_minor, payment.currency)}
                  </TableCell>
                  <TableCell>
                    <span className="flex flex-col gap-0.5">
                      <span className={cn('inline-flex items-center gap-1.5 font-medium', look.tone)}>
                        <Icon aria-hidden="true" className="size-4" />
                        {text.statuses[payment.status]}
                      </span>
                      {payment.status === 'approved' && payment.period_starts_at && payment.period_ends_at ? (
                        <span className="text-muted-foreground text-xs">
                          {fill(text.covers, {
                            from: formatInZone(payment.period_starts_at, zone, 'd MMM yyyy'),
                            to: formatInZone(payment.period_ends_at, zone, 'd MMM yyyy'),
                          })}
                        </span>
                      ) : null}
                      {payment.rejection_reason ? (
                        <span className="text-xs">
                          {fill(text.reason, { reason: payment.rejection_reason })}
                        </span>
                      ) : null}
                    </span>
                  </TableCell>
                  <TableCell>
                    {payment.receipt ? (
                      <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0"
                        onClick={() => setPreviewing(receiptIndex)}
                      >
                        {payment.receipt.name}
                      </Button>
                    ) : payment.recorded_by_platform ? (
                      <span className="text-muted-foreground text-xs">{text.noReceipt}</span>
                    ) : (
                      <span className="text-muted-foreground">{copy.contacts.none}</span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
      <Lightbox
        items={lightbox}
        index={previewing}
        onIndexChange={setPreviewing}
        onClose={() => setPreviewing(null)}
      />
    </section>
  )
}

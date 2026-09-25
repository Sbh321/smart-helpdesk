import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { ArrowLeftIcon, CalendarClockIcon, PauseIcon, PencilIcon, PlayIcon, WalletIcon } from 'lucide-react'
import { type FormEvent, type ReactNode, useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { SelectField } from '@/components/shared/select-field'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { TimeZoneField } from '@/components/shared/time-zone-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useRuntimeConfig } from '@/lib/config'
import { formatInZone } from '@/lib/datetime/format'
import { formatMoney, fromMinor, toMinor } from '@/lib/format/money'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  type Payment,
  type PaymentMethod,
  type PlatformTenant,
  platform,
  platformPaymentsQuery,
  platformPlansQuery,
  platformTenantQuery,
} from '../api'
import { PaymentReviewDialog } from './payment-review-dialog'
import { PaymentsTable } from './payments-table'
import { planPriceText } from './plan-text'
import { daysLeftText, SubscriptionBadge } from './subscription-badge'
import { TenantStatus } from './tenant-status'

const text = copy.platform.detail
const METHODS: PaymentMethod[] = ['bank_transfer', 'wallet', 'cash', 'other']

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/** One workspace for platform admins (ADR-0025): details, status, subscription and payments. */
export function WorkspaceDetailScreen({ tenantId }: { tenantId: string }) {
  const tenant = useQuery(platformTenantQuery(tenantId))
  const payments = useQuery(platformPaymentsQuery({ tenant_id: tenantId }))
  const { platformDomain } = useRuntimeConfig()
  const [dialog, setDialog] = useState<'edit' | 'suspend' | 'reactivate' | 'subscription' | 'payment' | null>(
    null,
  )
  const [reviewing, setReviewing] = useState<Payment | null>(null)
  const client = useQueryClient()
  const refresh = () => client.invalidateQueries({ queryKey: queryKeys.platform.all })

  const back = (
    <Link
      to="/platform/tenants"
      className="inline-flex w-fit items-center gap-1.5 text-muted-foreground text-sm hover:text-foreground"
    >
      <ArrowLeftIcon aria-hidden="true" className="size-4" />
      {text.back}
    </Link>
  )

  if (tenant.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        {back}
        <Skeleton className="h-10 w-72" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }
  if (tenant.isError) return <ErrorState error={tenant.error} onRetry={() => void tenant.refetch()} />

  const workspace = tenant.data
  const subscription = workspace.subscription
  const zone = workspace.timezone
  const date = (value: string | null, pattern = 'd MMM yyyy') =>
    value ? formatInZone(value, zone, pattern) : copy.contacts.none

  return (
    <>
      <PageHeader
        eyebrow={back}
        title={workspace.name}
        description={`app.${platformDomain}/${workspace.slug}`}
        actions={
          <>
            <Button type="button" variant="outline" onClick={() => setDialog('edit')}>
              <PencilIcon aria-hidden="true" />
              {text.edit}
            </Button>
            {workspace.status === 'suspended' ? (
              <Button type="button" variant="outline" onClick={() => setDialog('reactivate')}>
                <PlayIcon aria-hidden="true" />
                {text.reactivate}
              </Button>
            ) : (
              <Button type="button" variant="outline" onClick={() => setDialog('suspend')}>
                <PauseIcon aria-hidden="true" />
                {text.suspend}
              </Button>
            )}
          </>
        }
      />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={text.details}>
          <Rows
            rows={[
              [copy.platform.columns.status, <TenantStatus key="status" status={workspace.status} />],
              [text.owner, workspace.owner_email ?? copy.contacts.none],
              [text.timezone, zone],
              [text.created, date(workspace.created_at)],
            ]}
          />
        </Card>
        <Card
          title={text.subscription}
          actions={
            <>
              <Button type="button" size="sm" variant="outline" onClick={() => setDialog('subscription')}>
                <CalendarClockIcon aria-hidden="true" />
                {text.changePlan}
              </Button>
              <Button type="button" size="sm" onClick={() => setDialog('payment')}>
                <WalletIcon aria-hidden="true" />
                {text.recordPayment}
              </Button>
            </>
          }
        >
          <Rows
            rows={[
              [
                copy.platform.columns.subscription,
                <SubscriptionBadge key="state" state={subscription.state} />,
              ],
              [
                copy.platform.columns.plan,
                subscription.plan
                  ? `${subscription.plan.name} · ${planPriceText(subscription.plan)}`
                  : copy.contacts.none,
              ],
              [copy.platform.columns.ends, date(subscription.ends_at, 'd MMM yyyy, HH:mm')],
              ...(daysLeftText(subscription)
                ? [[text.timeLeft, daysLeftText(subscription)] as [string, ReactNode]]
                : []),
            ]}
          />
        </Card>
      </div>
      <section aria-labelledby="workspace-payments" className="flex flex-col gap-3">
        <h2 id="workspace-payments" className="font-semibold text-base">
          {text.payments}
        </h2>
        {payments.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : payments.isError ? (
          <ErrorState error={payments.error} onRetry={() => void payments.refetch()} />
        ) : payments.data.data.length === 0 ? (
          <p className="text-muted-foreground text-sm">{text.noPayments}</p>
        ) : (
          <PaymentsTable payments={payments.data.data} showWorkspace={false} onReview={setReviewing} />
        )}
      </section>

      <EditDialog
        workspace={workspace}
        open={dialog === 'edit'}
        onClose={() => setDialog(null)}
        onSaved={refresh}
      />
      <SuspendDialog
        workspace={workspace}
        open={dialog === 'suspend'}
        onClose={() => setDialog(null)}
        onDone={refresh}
      />
      <ConfirmDialog
        open={dialog === 'reactivate'}
        onOpenChange={(open) => setDialog(open ? 'reactivate' : null)}
        title={text.reactivate}
        description={workspace.name}
        confirmLabel={text.reactivate}
        failedTitle={text.failed}
        onConfirm={async () => {
          await platform.reactivateTenant(workspace.id)
          await refresh()
          toast.success(text.reactivated)
        }}
      />
      <SubscriptionDialog
        workspace={workspace}
        open={dialog === 'subscription'}
        onClose={() => setDialog(null)}
        onSaved={refresh}
      />
      <RecordPaymentDialog
        workspace={workspace}
        open={dialog === 'payment'}
        onClose={() => setDialog(null)}
        onSaved={refresh}
      />
      <PaymentReviewDialog payment={reviewing} onClose={() => setReviewing(null)} />
    </>
  )
}

function Card({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <section
      className="flex flex-col gap-3 rounded-card border border-border bg-surface p-4 shadow-1"
      aria-label={title}
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-semibold text-base">{title}</h2>
        {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
      </div>
      {children}
    </section>
  )
}

function Rows({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <dl className="divide-y divide-border text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="grid grid-cols-[9rem_minmax(0,1fr)] gap-3 py-2">
          <dt className="text-muted-foreground">{label}</dt>
          <dd className="min-w-0 break-words">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

function FormDialog({
  open,
  onClose,
  title,
  description,
  children,
}: {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? <DialogDescription>{description}</DialogDescription> : null}
        </DialogHeader>
        {open ? children : null}
      </DialogContent>
    </Dialog>
  )
}

function Footer({ busy, submit, onClose }: { busy: boolean; submit: string; onClose: () => void }) {
  return (
    <DialogFooter>
      <Button type="button" variant="outline" disabled={busy} onClick={onClose}>
        {copy.media.cancel}
      </Button>
      <Button type="submit" disabled={busy}>
        {submit}
      </Button>
    </DialogFooter>
  )
}

/** Runs a console form submission: busy state, server errors, success. */
function useSubmit(onSuccess: () => void) {
  const server = useServerErrors()
  const [busy, setBusy] = useState(false)
  const run = async (event: FormEvent, action: () => Promise<void>) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    try {
      await action()
      onSuccess()
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }
  return { server, busy, run }
}

function EditDialog({
  workspace,
  open,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  open: boolean
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  return (
    <FormDialog open={open} onClose={onClose} title={text.editTitle}>
      <EditForm workspace={workspace} onClose={onClose} onSaved={onSaved} />
    </FormDialog>
  )
}

function EditForm({
  workspace,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [name, setName] = useState(workspace.name)
  const [owner, setOwner] = useState(workspace.owner_email ?? '')
  const [timezone, setTimezone] = useState(workspace.timezone)
  const { server, busy, run } = useSubmit(onClose)
  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) =>
        void run(event, async () => {
          await platform.updateTenant(workspace.id, {
            name: name.trim(),
            owner_email: owner.trim() || undefined,
            timezone,
          })
          await onSaved()
          toast.success(text.saved)
        })
      }
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <TextField
        id="edit-name"
        label={copy.platform.newWorkspace.name}
        value={name}
        onValueChange={setName}
        errors={server.fields.name}
      />
      <TextField
        id="edit-owner"
        label={text.owner}
        type="email"
        value={owner}
        onValueChange={setOwner}
        errors={server.fields.owner_email}
      />
      <TimeZoneField
        id="edit-timezone"
        label={text.timezone}
        value={timezone}
        onValueChange={setTimezone}
        errors={server.fields.timezone}
      />
      <Footer busy={busy} submit={text.save} onClose={onClose} />
    </form>
  )
}

function SuspendDialog({
  workspace,
  open,
  onClose,
  onDone,
}: {
  workspace: PlatformTenant
  open: boolean
  onClose: () => void
  onDone: () => Promise<void>
}) {
  const [reason, setReason] = useState('')
  const { server, busy, run } = useSubmit(onClose)
  return (
    <FormDialog
      open={open}
      onClose={onClose}
      title={fill(text.suspendTitle, { name: workspace.name })}
      description={text.suspendBody}
    >
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(event) =>
          void run(event, async () => {
            await platform.suspendTenant(workspace.id, reason.trim() || undefined)
            await onDone()
            toast.success(text.suspended)
          })
        }
      >
        {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
        <TextareaField
          id="suspend-reason"
          label={text.suspendReason}
          value={reason}
          onValueChange={setReason}
        />
        <Footer busy={busy} submit={text.suspend} onClose={onClose} />
      </form>
    </FormDialog>
  )
}

function SubscriptionDialog({
  workspace,
  open,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  open: boolean
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  return (
    <FormDialog open={open} onClose={onClose} title={text.changeTitle} description={text.changeDescription}>
      <SubscriptionForm workspace={workspace} onClose={onClose} onSaved={onSaved} />
    </FormDialog>
  )
}

function SubscriptionForm({
  workspace,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const plans = useQuery(platformPlansQuery())
  const [planId, setPlanId] = useState<string | null>(workspace.subscription.plan?.id ?? null)
  const [endsOn, setEndsOn] = useState(workspace.subscription.ends_at?.slice(0, 10) ?? today())
  const { server, busy, run } = useSubmit(onClose)
  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) =>
        void run(event, async () => {
          await platform.changeSubscription(workspace.id, {
            plan_id: planId ?? '',
            ends_at: `${endsOn}T23:59:59Z`,
          })
          await onSaved()
          toast.success(text.changed)
        })
      }
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <SelectField
        id="subscription-plan"
        label={copy.platform.record.plan}
        value={planId}
        onValueChange={(value) => setPlanId(String(value))}
        options={(plans.data ?? []).map((plan) => ({
          value: plan.id,
          label: `${plan.name} · ${planPriceText(plan)}`,
        }))}
        errors={server.fields.plan_id}
      />
      <TextField
        id="subscription-ends"
        label={text.endsAt}
        type="date"
        value={endsOn}
        onValueChange={setEndsOn}
        errors={server.fields.ends_at}
      />
      <Footer busy={busy} submit={text.save} onClose={onClose} />
    </form>
  )
}

function RecordPaymentDialog({
  workspace,
  open,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  open: boolean
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  return (
    <FormDialog
      open={open}
      onClose={onClose}
      title={copy.platform.record.title}
      description={copy.platform.record.description}
    >
      <RecordPaymentForm workspace={workspace} onClose={onClose} onSaved={onSaved} />
    </FormDialog>
  )
}

function RecordPaymentForm({
  workspace,
  onClose,
  onSaved,
}: {
  workspace: PlatformTenant
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const record = copy.platform.record
  const plans = useQuery(platformPlansQuery())
  const paid = (plans.data ?? []).filter((plan) => plan.kind === 'paid' && plan.is_active)
  const [planId, setPlanId] = useState<string | null>(null)
  const plan = paid.find((candidate) => candidate.id === planId) ?? paid[0] ?? null
  const [periods, setPeriods] = useState('1')
  const [amount, setAmount] = useState<string | null>(null)
  const [paidOn, setPaidOn] = useState(today)
  const [method, setMethod] = useState<PaymentMethod>('bank_transfer')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const expected = plan ? plan.price_minor * (Number(periods) || 1) : 0
  const shownAmount = amount ?? (plan ? fromMinor(expected) : '')
  const { server, busy, run } = useSubmit(onClose)
  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) =>
        void run(event, async () => {
          const payment = await platform.recordPayment(workspace.id, {
            plan_id: plan?.id ?? '',
            periods: Number(periods) || 0,
            amount_minor: toMinor(shownAmount) ?? 0,
            paid_on: paidOn,
            method,
            reference: reference.trim() || undefined,
            note: note.trim() || undefined,
          })
          await onSaved()
          toast.success(
            fill(record.recorded, {
              date: payment.period_ends_at
                ? formatInZone(payment.period_ends_at, workspace.timezone, 'd MMM yyyy')
                : '',
            }),
          )
        })
      }
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_7rem]">
        <SelectField
          id="record-plan"
          label={record.plan}
          value={plan?.id ?? null}
          onValueChange={(value) => {
            setPlanId(String(value))
            setAmount(null)
          }}
          options={paid.map((candidate) => ({
            value: candidate.id,
            label: `${candidate.name} · ${planPriceText(candidate)}`,
          }))}
          errors={server.fields.plan_id}
        />
        <TextField
          id="record-periods"
          label={record.periods}
          type="number"
          min={1}
          max={36}
          value={periods}
          onValueChange={(value) => {
            setPeriods(value)
            setAmount(null)
          }}
          errors={server.fields.periods}
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="record-amount"
          label={`${record.amount} (${plan?.currency ?? 'NPR'})`}
          inputMode="decimal"
          value={shownAmount}
          onValueChange={setAmount}
          description={
            plan ? fill(record.amountHint, { amount: formatMoney(expected, plan.currency) }) : undefined
          }
          errors={server.fields.amount_minor}
        />
        <TextField
          id="record-paid-on"
          label={record.paidOn}
          type="date"
          value={paidOn}
          onValueChange={setPaidOn}
          errors={server.fields.paid_on}
        />
      </div>
      <SelectField
        id="record-method"
        label={record.method}
        value={method}
        onValueChange={(value) => setMethod(value as PaymentMethod)}
        options={METHODS.map((value) => ({ value, label: copy.platform.methods[value] }))}
        errors={server.fields.method}
      />
      <TextField
        id="record-reference"
        label={record.reference}
        description={record.referenceHint}
        value={reference}
        onValueChange={setReference}
        errors={server.fields.reference}
      />
      <TextareaField
        id="record-note"
        label={record.note}
        value={note}
        onValueChange={setNote}
        errors={server.fields.note}
      />
      <Footer busy={busy} submit={record.submit} onClose={onClose} />
    </form>
  )
}

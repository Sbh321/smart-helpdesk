import { useQuery, useQueryClient } from '@tanstack/react-query'
import { LayersIcon, PencilIcon, PlusIcon } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { PageHeader } from '@/components/shared/page-header'
import { SelectField } from '@/components/shared/select-field'
import { TextField } from '@/components/shared/text-field'
import { TextareaField } from '@/components/shared/textarea-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { fromMinor, toMinor } from '@/lib/format/money'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type Plan, type PlanKind, platform, platformPlansQuery } from '../api'
import { planLengthText, planPriceText } from './plan-text'

const text = copy.platform.plans

/** Plans (ADR-0025 §1): what workspaces can be on; archived plans stay on their workspaces. */
export function PlansScreen() {
  const plans = useQuery(platformPlansQuery())
  const [editing, setEditing] = useState<Plan | 'new' | null>(null)

  return (
    <>
      <PageHeader
        title={text.title}
        description={text.description}
        actions={
          <Button type="button" onClick={() => setEditing('new')}>
            <PlusIcon aria-hidden="true" />
            {text.create}
          </Button>
        }
      />
      {plans.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : plans.isError ? (
        <ErrorState error={plans.error} onRetry={() => void plans.refetch()} />
      ) : plans.data.length === 0 ? (
        <EmptyState icon={LayersIcon} title={text.title} description={text.description} />
      ) : (
        <Table aria-label={text.tableLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{text.columns.name}</TableHead>
              <TableHead>{text.columns.kind}</TableHead>
              <TableHead>{text.columns.price}</TableHead>
              <TableHead>{text.columns.length}</TableHead>
              <TableHead className="text-right">{text.columns.workspaces}</TableHead>
              <TableHead>{text.columns.status}</TableHead>
              <TableHead>
                <span className="sr-only">{text.edit}</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {plans.data.map((plan) => (
              <TableRow key={plan.id}>
                <TableCell>
                  <span className="flex flex-col">
                    <span className="font-medium">{plan.name}</span>
                    <span className="font-mono text-muted-foreground text-xs">{plan.code}</span>
                  </span>
                </TableCell>
                <TableCell>{text.kinds[plan.kind]}</TableCell>
                <TableCell className="tabular-nums">{planPriceText(plan)}</TableCell>
                <TableCell>{planLengthText(plan)}</TableCell>
                <TableCell className="text-right tabular-nums">{plan.subscriptions_count ?? 0}</TableCell>
                <TableCell>
                  <Badge variant={plan.is_active ? 'secondary' : 'outline'}>
                    {plan.is_active ? text.active : text.archived}
                  </Badge>
                </TableCell>
                <TableCell className="text-right">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    aria-label={fill(text.editNamed, { name: plan.name })}
                    onClick={() => setEditing(plan)}
                  >
                    <PencilIcon aria-hidden="true" />
                    {text.edit}
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <Dialog
        open={editing !== null}
        onOpenChange={(open) => {
          if (!open) setEditing(null)
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editing === 'new' || editing === null
                ? text.createTitle
                : fill(text.editTitle, { name: editing.name })}
            </DialogTitle>
            <DialogDescription>{text.description}</DialogDescription>
          </DialogHeader>
          {editing !== null ? (
            <PlanForm plan={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
          ) : null}
        </DialogContent>
      </Dialog>
    </>
  )
}

function PlanForm({ plan, onDone }: { plan: Plan | null; onDone: () => void }) {
  const fields = text.fields
  const client = useQueryClient()
  const server = useServerErrors()
  const [busy, setBusy] = useState(false)
  const [kind, setKind] = useState<PlanKind>(plan?.kind ?? 'paid')
  const [name, setName] = useState(plan?.name ?? '')
  const [code, setCode] = useState(plan?.code ?? '')
  const [description, setDescription] = useState(plan?.description ?? '')
  const [price, setPrice] = useState(plan ? fromMinor(plan.price_minor) : '')
  const [currency, setCurrency] = useState(plan?.currency ?? 'NPR')
  const [months, setMonths] = useState(String(plan?.period_months ?? 1))
  const [days, setDays] = useState(String(plan?.trial_days ?? 14))
  const [active, setActive] = useState(plan?.is_active ?? true)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setBusy(true)
    const common = {
      name: name.trim(),
      description: description.trim() || null,
      is_active: active,
      ...(kind === 'paid'
        ? {
            price_minor: toMinor(price) ?? -1,
            currency: currency.trim().toUpperCase(),
            period_months: Number(months),
          }
        : { trial_days: Number(days) }),
    }
    try {
      await (plan
        ? platform.updatePlan(plan.id, common)
        : platform.createPlan({ ...common, code: code.trim(), kind }))
      await client.invalidateQueries({ queryKey: queryKeys.platform.all })
      toast.success(text.saved)
      onDone()
    } catch (error) {
      server.capture(error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-4">
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="plan-name"
          label={fields.name}
          value={name}
          onValueChange={setName}
          errors={server.fields.name}
        />
        {plan ? null : (
          <SelectField
            id="plan-kind"
            label={fields.kind}
            value={kind}
            onValueChange={(value) => setKind(value as PlanKind)}
            options={(['paid', 'trial'] as const).map((value) => ({ value, label: text.kinds[value] }))}
          />
        )}
      </div>
      {plan ? null : (
        <TextField
          id="plan-code"
          label={fields.code}
          description={fields.codeHint}
          value={code}
          onValueChange={(value) => setCode(value.toLowerCase())}
          errors={server.fields.code}
          spellCheck={false}
        />
      )}
      <TextareaField
        id="plan-description"
        label={fields.description}
        value={description}
        onValueChange={setDescription}
        errors={server.fields.description}
      />
      {kind === 'paid' ? (
        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_6rem_8rem]">
          <TextField
            id="plan-price"
            label={fields.price}
            inputMode="decimal"
            value={price}
            onValueChange={setPrice}
            errors={server.fields.price_minor}
          />
          <TextField
            id="plan-currency"
            label={fields.currency}
            value={currency}
            onValueChange={setCurrency}
            maxLength={3}
            errors={server.fields.currency}
          />
          <TextField
            id="plan-months"
            label={fields.periodMonths}
            type="number"
            min={1}
            max={36}
            value={months}
            onValueChange={setMonths}
            errors={server.fields.period_months}
          />
        </div>
      ) : (
        <TextField
          id="plan-days"
          label={fields.trialDays}
          type="number"
          min={1}
          max={365}
          value={days}
          onValueChange={setDays}
          errors={server.fields.trial_days}
        />
      )}
      <div className="flex items-start gap-3">
        <Switch
          id="plan-active"
          checked={active}
          onCheckedChange={setActive}
          aria-describedby="plan-active-hint"
        />
        <div className="flex flex-col gap-1">
          <Label htmlFor="plan-active">{fields.active}</Label>
          <p id="plan-active-hint" className="text-muted-foreground text-xs">
            {fields.activeHint}
          </p>
          {server.fields.is_active ? (
            <p role="alert" className="text-destructive text-sm">
              {server.fields.is_active.join(' ')}
            </p>
          ) : null}
        </div>
      </div>
      <DialogFooter>
        <Button type="button" variant="outline" disabled={busy} onClick={onDone}>
          {copy.media.cancel}
        </Button>
        <Button type="submit" disabled={busy}>
          {text.save}
        </Button>
      </DialogFooter>
    </form>
  )
}

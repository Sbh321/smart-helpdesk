import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { UnsavedChangesGuard } from '@/components/shared/save-bar'
import { SelectField } from '@/components/shared/select-field'
import { SettingsPage } from '@/components/shared/settings-page'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { SlaDefaultsCard } from '@/features/settings'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  type Calendar,
  createPolicy,
  type SlaPolicy,
  type SlaPolicyInput,
  slaQueries,
  updatePolicy,
} from '../api/sla-queries'
import { workingTime } from '../readable'
import {
  ALL_TIERS,
  ALWAYS_OPEN,
  type PolicyFormValues,
  policyFormSchema,
  SLA_PRIORITIES,
  SLA_TIERS,
} from '../schemas'
import { PolicyTargets } from './sla-readable'

const TIER_OPTIONS: { value: PolicyFormValues['tier']; label: string }[] = [
  { value: ALL_TIERS, label: copy.sla.allTiers },
  ...SLA_TIERS.map((value) => ({ value, label: copy.sla.tiers[value] })),
]
const TARGET_FIELDS = [
  { name: 'first_response_minutes', label: copy.sla.firstResponse },
  { name: 'resolution_minutes', label: copy.sla.resolution },
] as const

function isTier(value: string | null): value is (typeof SLA_TIERS)[number] {
  return (SLA_TIERS as readonly string[]).includes(value ?? '')
}

function initialValues(policy: SlaPolicy | null): PolicyFormValues {
  return {
    name: policy?.name ?? '',
    tier: policy && isTier(policy.applies_to_tier) ? policy.applies_to_tier : ALL_TIERS,
    calendar: policy?.calendar_id ?? ALWAYS_OPEN,
    warning_fraction: String(policy?.warning_fraction ?? 0.75),
    targets: SLA_PRIORITIES.map((priority_level) => {
      const target = policy?.targets.find((row) => row.priority_level === priority_level)
      return {
        priority_level,
        first_response_minutes: String(target?.first_response_minutes ?? 60),
        resolution_minutes: String(target?.resolution_minutes ?? 480),
      }
    }),
  }
}

function toInput(values: PolicyFormValues, policy: SlaPolicy | null): SlaPolicyInput {
  return {
    name: values.name.trim(),
    warning_fraction: Number(values.warning_fraction),
    applies_to_tier: values.tier === ALL_TIERS ? null : values.tier,
    calendar_id: values.calendar === ALWAYS_OPEN ? null : values.calendar,
    ...(policy ? { is_default: policy.is_default } : {}),
    targets: values.targets.map((target) => ({
      priority_level: target.priority_level,
      first_response_minutes: Number(target.first_response_minutes),
      resolution_minutes: Number(target.resolution_minutes),
    })),
  }
}

function PolicyForm({
  policy,
  calendars,
  onDone,
}: {
  policy: SlaPolicy | null
  calendars: readonly Pick<Calendar, 'id' | 'name'>[]
  onDone: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: initialValues(policy),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: policyFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const input = toInput(value, policy)
        await (policy ? updatePolicy(policy.id, input) : createPolicy(input))
        await client.invalidateQueries({ queryKey: queryKeys.sla.all(tenantId) })
        toast.success(copy.sla.policySaved)
        onDone()
      } catch (error) {
        server.capture(error)
      }
    },
  })
  const calendarOptions = [
    { value: ALWAYS_OPEN, label: copy.sla.alwaysOpen },
    ...calendars.map((item) => ({ value: item.id, label: item.name })),
  ]

  return (
    <form
      noValidate
      aria-label={policy ? fill(copy.sla.editNamed, { name: policy.name }) : copy.sla.newPolicy}
      className="flex max-w-2xl flex-col gap-5 rounded-lg border border-border p-4 bg-surface"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.sla.policyFailed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="sla-policy-name"
              label={copy.settings.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="tier">
          {(field) => (
            <SelectField
              id="sla-policy-tier"
              label={copy.sla.tier}
              value={field.state.value}
              onValueChange={field.handleChange}
              options={TIER_OPTIONS}
              errors={mergeMessages(field.state.meta.errors, server.fields.applies_to_tier)}
            />
          )}
        </form.Field>
        <form.Field name="calendar">
          {(field) => (
            <SelectField
              id="sla-policy-calendar"
              label={copy.sla.calendar}
              value={field.state.value}
              onValueChange={field.handleChange}
              options={calendarOptions}
              errors={mergeMessages(field.state.meta.errors, server.fields.calendar_id)}
            />
          )}
        </form.Field>
        <form.Field name="warning_fraction">
          {(field) => (
            <TextField
              id="sla-policy-warning"
              label={copy.sla.warningFraction}
              description={copy.sla.warningFractionDescription}
              type="number"
              inputMode="decimal"
              min="0.10"
              max="0.95"
              step="0.01"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.warning_fraction)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <FieldSet>
        <FieldLegend>{copy.sla.targets}</FieldLegend>
        {(server.exact.targets ?? []).map((message) => (
          <p key={message} role="alert" className="text-sm text-destructive">
            {message}
          </p>
        ))}
        {SLA_PRIORITIES.map((priority, index) => (
          <div key={priority} className="grid gap-3 sm:grid-cols-2">
            {TARGET_FIELDS.map((target) => (
              <form.Field key={target.name} name={`targets[${index}].${target.name}`}>
                {(field) => (
                  <TextField
                    id={`sla-${priority}-${target.name}`}
                    label={fill(copy.sla.targetField, { priority, target: target.label })}
                    description={fill(copy.sla.readable.targetHint, {
                      time: workingTime(Number(field.state.value)),
                    })}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    value={field.state.value}
                    onValueChange={field.handleChange}
                    onBlur={field.handleBlur}
                    errors={mergeMessages(
                      field.state.meta.errors,
                      server.exact[`targets.${index}.${target.name}`],
                    )}
                  />
                )}
              </form.Field>
            ))}
          </div>
        ))}
      </FieldSet>
      <form.Subscribe
        selector={(state) => ({ submitting: state.isSubmitting, dirty: !state.isDefaultValue })}
      >
        {({ submitting, dirty }) => (
          <div className="flex gap-2">
            <Button type="submit" disabled={submitting}>
              {submitting ? copy.sla.saving : copy.sla.savePolicy}
            </Button>
            <Button type="button" variant="outline" disabled={submitting} onClick={onDone}>
              {copy.sla.cancel}
            </Button>
            <UnsavedChangesGuard dirty={dirty && !submitting} />
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}

export function PolicySettings() {
  const allowed = useCan('tickets.view')
  if (!allowed) return <ForbiddenState />
  return <PolicyList />
}

/**
 * Settings → SLA policies (roadmap M4-12): each policy as a card that says what it promises (a table
 * of targets per priority in working time), to whom (organisation tier), on which calendar and when
 * it warns, so the page is readable without opening a form or the docs.
 */
function PolicyList() {
  const canManage = useCan('sla.manage')
  const tenantId = useSession().session?.tenant.id ?? ''
  const policies = useQuery({ ...slaQueries.policies(tenantId), enabled: tenantId !== '' })
  const calendars = useQuery({ ...slaQueries.calendars(tenantId), enabled: tenantId !== '' })
  const [editing, setEditing] = useState<SlaPolicy | null | undefined>(undefined)
  const calendarName = (id: string | null) =>
    id === null
      ? copy.sla.alwaysOpen
      : (calendars.data?.find((row) => row.id === id)?.name ?? copy.sla.alwaysOpen)
  const readable = copy.sla.readable

  return (
    <SettingsPage
      title={copy.sla.policies}
      description={readable.pageDescription}
      actions={canManage ? <Button onClick={() => setEditing(null)}>{copy.sla.addPolicy}</Button> : null}
    >
      {editing !== undefined ? (
        <PolicyForm
          key={editing?.id ?? 'new'}
          policy={editing}
          calendars={calendars.data ?? []}
          onDone={() => setEditing(undefined)}
        />
      ) : null}
      {policies.isPending ? (
        <Skeleton className="h-24 w-full" />
      ) : policies.isError ? (
        <ErrorState error={policies.error} onRetry={() => void policies.refetch()} />
      ) : policies.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">{copy.sla.noPolicies}</p>
      ) : (
        <ul className="grid gap-4 xl:grid-cols-2">
          {policies.data.map((policy) => (
            <li
              key={policy.id}
              className="flex flex-col gap-3 rounded-lg border border-border p-4 bg-surface"
            >
              <div className="flex items-start justify-between gap-3">
                <h3 className="font-semibold">{policy.name}</h3>
                {canManage ? (
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label={fill(copy.sla.editNamed, { name: policy.name })}
                    onClick={() => setEditing(policy)}
                  >
                    {copy.sla.edit}
                  </Button>
                ) : null}
              </div>
              <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                <dt className="text-muted-foreground">{readable.appliesTo}</dt>
                <dd>
                  {policy.is_default
                    ? copy.sla.default
                    : isTier(policy.applies_to_tier)
                      ? copy.sla.tiers[policy.applies_to_tier]
                      : copy.sla.allTiers}
                </dd>
                <dt className="text-muted-foreground">{readable.calendar}</dt>
                <dd>{calendarName(policy.calendar_id)}</dd>
                <dt className="text-muted-foreground">{readable.warnsAt}</dt>
                <dd>{fill(readable.warnsAtValue, { percent: Math.round(policy.warning_fraction * 100) })}</dd>
                <dt className="text-muted-foreground">{readable.version}</dt>
                <dd className="tabular-nums">{policy.version}</dd>
              </dl>
              <PolicyTargets policy={policy} />
            </li>
          ))}
        </ul>
      )}
      <SlaDefaultsCard />
    </SettingsPage>
  )
}

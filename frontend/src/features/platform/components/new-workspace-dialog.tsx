import { useQuery, useQueryClient } from '@tanstack/react-query'
import { type FormEvent, useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SelectField } from '@/components/shared/select-field'
import { TextField } from '@/components/shared/text-field'
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
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useRuntimeConfig } from '@/lib/config'
import { browserTimeZone } from '@/lib/datetime/time-zones'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type PlatformTenant, platform, platformPlansQuery } from '../api'
import { planPriceText } from './plan-text'

const text = copy.platform.newWorkspace

/** "Acme Support Ltd." → "acme-support-ltd": the address a name suggests. */
export function slugFrom(name: string): string {
  return name
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
    .replace(/-+$/, '')
}

/**
 * "New workspace" (ADR-0025 §8): name and address, the owner who is invited by email, the time zone and
 * the plan it starts on (the active trial by default, or periods of a paid plan).
 */
export function NewWorkspaceDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated: (tenant: PlatformTenant) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{text.title}</DialogTitle>
          <DialogDescription>{text.description}</DialogDescription>
        </DialogHeader>
        {open ? (
          <NewWorkspaceForm
            onCancel={() => onOpenChange(false)}
            onCreated={(tenant) => {
              onOpenChange(false)
              onCreated(tenant)
            }}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function NewWorkspaceForm({
  onCancel,
  onCreated,
}: {
  onCancel: () => void
  onCreated: (tenant: PlatformTenant) => void
}) {
  const client = useQueryClient()
  const { platformDomain } = useRuntimeConfig()
  const plans = useQuery(platformPlansQuery())
  const server = useServerErrors()
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
  const [ownerName, setOwnerName] = useState('')
  const [ownerEmail, setOwnerEmail] = useState('')
  const [timezone, setTimezone] = useState(browserTimeZone)
  const [planId, setPlanId] = useState<string | null>(null)
  const [periods, setPeriods] = useState('1')
  const [saving, setSaving] = useState(false)

  const offered = (plans.data ?? []).filter((plan) => plan.is_active)
  const chosen =
    offered.find((plan) => plan.id === planId) ?? offered.find((plan) => plan.kind === 'trial') ?? null
  const address = slug || slugFrom(name)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    server.reset()
    setSaving(true)
    try {
      const tenant = await platform.createTenant({
        name: name.trim(),
        slug: address,
        owner_name: ownerName.trim() || undefined,
        owner_email: ownerEmail.trim(),
        timezone,
        plan_id: chosen?.id ?? null,
        periods: chosen?.kind === 'paid' ? Number(periods) || 1 : undefined,
      })
      await client.invalidateQueries({ queryKey: queryKeys.platform.all })
      toast.success(fill(text.created, { name: tenant.name }))
      onCreated(tenant)
    } catch (error) {
      server.capture(error)
    } finally {
      setSaving(false)
    }
  }

  return (
    <form noValidate onSubmit={(event) => void submit(event)} className="flex flex-col gap-4">
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <TextField
        id="workspace-name"
        label={text.name}
        value={name}
        onValueChange={(value) => {
          setName(value)
          if (!slugEdited) setSlug(slugFrom(value))
        }}
        errors={server.fields.name}
        autoComplete="off"
      />
      <TextField
        id="workspace-slug"
        label={text.slug}
        value={address}
        onValueChange={(value) => {
          setSlugEdited(true)
          setSlug(value.toLowerCase())
        }}
        description={fill(text.slugHint, { url: `app.${platformDomain}/${address || '…'}` })}
        errors={server.fields.slug}
        autoComplete="off"
        spellCheck={false}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField
          id="workspace-owner-name"
          label={text.ownerName}
          value={ownerName}
          onValueChange={setOwnerName}
          errors={server.fields.owner_name}
          autoComplete="off"
        />
        <TextField
          id="workspace-owner-email"
          label={text.ownerEmail}
          type="email"
          value={ownerEmail}
          onValueChange={setOwnerEmail}
          errors={server.fields.owner_email}
          autoComplete="off"
        />
      </div>
      <TimeZoneField
        id="workspace-timezone"
        label={text.timezone}
        value={timezone}
        onValueChange={setTimezone}
        errors={server.fields.timezone}
      />
      <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
        <SelectField
          id="workspace-plan"
          label={text.plan}
          value={chosen?.id ?? null}
          onValueChange={(value) => setPlanId(String(value))}
          options={offered.map((plan) => ({
            value: plan.id,
            label: `${plan.name} · ${planPriceText(plan)}`,
          }))}
          errors={server.fields.plan_id}
          disabled={plans.isPending}
        />
        {chosen?.kind === 'paid' ? (
          <TextField
            id="workspace-periods"
            label={text.periods}
            type="number"
            inputMode="numeric"
            min={1}
            max={36}
            value={periods}
            onValueChange={setPeriods}
            errors={server.fields.periods}
          />
        ) : null}
      </div>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel} disabled={saving}>
          {copy.media.cancel}
        </Button>
        <Button type="submit" disabled={saving}>
          {saving ? text.creating : text.create}
        </Button>
      </DialogFooter>
    </form>
  )
}

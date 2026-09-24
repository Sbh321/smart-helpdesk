import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRoundIcon, MoreHorizontalIcon, PlusIcon, TriangleAlertIcon, WebhookIcon } from 'lucide-react'
import { useState } from 'react'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SettingsPage } from '@/components/shared/settings-page'
import { TextField } from '@/components/shared/text-field'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { FieldGroup } from '@/components/ui/field'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { CheckboxGroup } from '@/features/users'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  createWebhook,
  deleteWebhook,
  rotateWebhookSecret,
  setWebhookActive,
  testWebhook,
  updateWebhook,
  type Webhook,
  type WebhookEventType,
  type WebhookInput,
  type WebhookWithSecret,
  webhookQueries,
} from '../api/webhook-queries'
import { type WebhookFormValues, webhookFormSchema } from '../schemas'
import { CopyableValue } from './copyable-value'
import { WebhookDeliveries } from './webhook-deliveries'

const text = copy.webhooks

function errorText(error: unknown, fallback: string): string {
  return isApiError(error) ? (error.detail ?? error.title) : fallback
}

function WebhookForm({
  events,
  webhook,
  onSaved,
  onCancel,
}: {
  events: readonly WebhookEventType[]
  /** Editing when given, creating otherwise. */
  webhook: Webhook | null
  onSaved: (saved: Webhook | WebhookWithSecret) => void
  onCancel: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const defaultValues: WebhookFormValues = {
    name: webhook?.name ?? '',
    url: webhook?.url ?? '',
    events: webhook ? [...webhook.events] : [],
  }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: webhookFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const parsed = webhookFormSchema.parse(value)
        const input: WebhookInput = {
          name: parsed.name,
          url: parsed.url,
          events: parsed.events as WebhookInput['events'],
        }
        const saved = webhook ? await updateWebhook(webhook.id, input) : await createWebhook(input)
        await client.invalidateQueries({ queryKey: queryKeys.webhooks.list(tenantId) })
        onSaved(saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={webhook ? fill(text.editTitle, { name: webhook.name }) : text.createTitle}
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="webhook-name"
              label={text.name}
              description={text.nameHint}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="url">
          {(field) => (
            <TextField
              id="webhook-url"
              label={text.url}
              description={text.urlHint}
              type="url"
              inputMode="url"
              autoComplete="off"
              spellCheck={false}
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.url)}
            />
          )}
        </form.Field>
        <form.Field name="events">
          {(field) => (
            <CheckboxGroup
              id="webhook-events"
              legend={text.events}
              description={text.eventsHint}
              options={events.map((event) => ({
                value: event.type,
                label: `${event.type}: ${event.description}`,
              }))}
              value={field.state.value}
              onChange={field.handleChange}
              errors={mergeMessages(field.state.meta.errors, server.fields.events)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <DialogFooter>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={onCancel}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? text.saving : webhook ? text.saveChanges : text.save}
            </Button>
          </DialogFooter>
        )}
      </form.Subscribe>
    </form>
  )
}

/** The only view of a signing secret (after create or rotate). Closing the dialog drops it. */
function SecretView({
  saved,
  rotated,
  onDone,
}: {
  saved: WebhookWithSecret
  rotated: boolean
  onDone: () => void
}) {
  return (
    <div className="flex flex-col gap-4">
      <Alert>
        <KeyRoundIcon aria-hidden="true" />
        <AlertTitle>{text.secretTitle}</AlertTitle>
        <AlertDescription>{rotated ? text.rotatedDescription : text.secretDescription}</AlertDescription>
      </Alert>
      <CopyableValue id="webhook-secret" label={text.secret} value={saved.secret} />
      <DialogFooter>
        <Button type="button" onClick={onDone}>
          {text.done}
        </Button>
      </DialogFooter>
    </div>
  )
}

function hasSecret(saved: Webhook | WebhookWithSecret): saved is WebhookWithSecret {
  return 'secret' in saved && typeof saved.secret === 'string'
}

/** Create (then the secret, once), edit, or show a rotated secret. */
function WebhookDialog({
  open,
  webhook,
  rotated,
  onClose,
}: {
  open: boolean
  webhook: Webhook | null
  /** A rotation result to show instead of the form. */
  rotated: WebhookWithSecret | null
  onClose: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const events = useQuery({
    ...webhookQueries.events(tenantId),
    enabled: open && rotated === null && tenantId !== '',
  })
  const [created, setCreated] = useState<WebhookWithSecret | null>(null)
  const secret = rotated ?? created
  const close = () => {
    setCreated(null)
    onClose()
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // The secret view closes only through its button, so the secret is not dismissed by accident.
        if (!next && secret === null) close()
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {secret ? secret.name : webhook ? fill(text.editTitle, { name: webhook.name }) : text.createTitle}
          </DialogTitle>
          <DialogDescription>
            {secret ? text.secretTitle : webhook ? text.editDescription : text.createDescription}
          </DialogDescription>
        </DialogHeader>
        {!open ? null : secret ? (
          <SecretView saved={secret} rotated={rotated !== null} onDone={close} />
        ) : events.isPending ? (
          <Skeleton className="h-64 w-full" />
        ) : events.isError ? (
          <ErrorState error={events.error} onRetry={() => void events.refetch()} />
        ) : (
          <WebhookForm
            events={events.data}
            webhook={webhook}
            onCancel={close}
            onSaved={(saved) => {
              if (hasSecret(saved)) {
                setCreated(saved)
              } else {
                toast.success(fill(text.savedToast, { name: saved.name }))
                close()
              }
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

type PendingAction = { kind: 'disable' | 'rotate' | 'delete'; webhook: Webhook }

function WebhookRow({
  webhook,
  timeZone,
  showingDeliveries,
  onShowDeliveries,
  onEdit,
  onTest,
  onEnable,
  onAsk,
}: {
  webhook: Webhook
  timeZone: string
  showingDeliveries: boolean
  onShowDeliveries: (webhook: Webhook) => void
  onEdit: (webhook: Webhook) => void
  onTest: (webhook: Webhook) => void
  onEnable: (webhook: Webhook) => void
  onAsk: (action: PendingAction) => void
}) {
  return (
    <TableRow>
      <TableCell>
        <span className="font-medium">{webhook.name}</span>
        <span className="block max-w-72 truncate font-mono text-xs text-muted-foreground">{webhook.url}</span>
      </TableCell>
      <TableCell>
        <ul className="flex flex-wrap gap-1" aria-label={fill(text.eventsOf, { name: webhook.name })}>
          {webhook.events.map((event) => (
            <li key={event}>
              <Badge variant="outline" className="font-mono">
                {event}
              </Badge>
            </li>
          ))}
        </ul>
      </TableCell>
      <TableCell>
        {webhook.is_active ? (
          <Badge variant="secondary">{text.active}</Badge>
        ) : (
          <Badge variant="outline">
            <TriangleAlertIcon aria-hidden="true" className="text-destructive" />
            {webhook.disabled_reason === 'consecutive_failures' ? text.disabledAuto : text.disabled}
          </Badge>
        )}
      </TableCell>
      <TableCell>
        {webhook.last_delivery_at ? formatInZone(webhook.last_delivery_at, timeZone) : text.never}
      </TableCell>
      <TableCell>
        <div className="flex justify-end gap-2">
          <Button
            size="sm"
            variant={showingDeliveries ? 'secondary' : 'outline'}
            aria-label={fill(text.showDeliveriesNamed, { name: webhook.name })}
            aria-pressed={showingDeliveries}
            onClick={() => onShowDeliveries(webhook)}
          >
            {text.showDeliveries}
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger
              render={
                <Button
                  size="icon-sm"
                  variant="outline"
                  aria-label={fill(text.actionsFor, { name: webhook.name })}
                />
              }
            >
              <MoreHorizontalIcon aria-hidden="true" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => onTest(webhook)}>{text.sendTest}</DropdownMenuItem>
              <DropdownMenuItem onClick={() => onEdit(webhook)}>{text.edit}</DropdownMenuItem>
              {webhook.is_active ? (
                <DropdownMenuItem onClick={() => onAsk({ kind: 'disable', webhook })}>
                  {text.disable}
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem onClick={() => onEnable(webhook)}>{text.enable}</DropdownMenuItem>
              )}
              <DropdownMenuItem onClick={() => onAsk({ kind: 'rotate', webhook })}>
                {text.rotate}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onClick={() => onAsk({ kind: 'delete', webhook })}>
                {text.delete}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </TableCell>
    </TableRow>
  )
}

/**
 * Settings → Developer → Webhooks (roadmap M3-05, docs/07-api/webhooks.md): subscriptions with their
 * events, the signing secret shown once, enable/disable, secret rotation, test deliveries and the
 * delivery log with manual retry. Permission `integrations.manage`.
 */
export function WebhooksSettings() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const allowed = useCan('integrations.manage')
  const queryClient = useQueryClient()
  const webhooks = useQuery({ ...webhookQueries.list(tenantId), enabled: allowed && tenantId !== '' })
  const [dialog, setDialog] = useState<{ webhook: Webhook | null; rotated: WebhookWithSecret | null } | null>(
    null,
  )
  const [pending, setPending] = useState<PendingAction | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)

  if (!allowed) return <ForbiddenState />

  const refreshList = () => queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.list(tenantId) })
  const refreshDeliveries = (id: string) =>
    queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.deliveries(tenantId, id) })
  const selected = webhooks.data?.find((webhook) => webhook.id === selectedId) ?? null

  async function sendTest(webhook: Webhook) {
    try {
      await testWebhook(webhook.id)
      toast.success(fill(text.testQueued, { name: webhook.name }))
      setSelectedId(webhook.id)
      await refreshDeliveries(webhook.id)
    } catch (error) {
      toast.error(errorText(error, text.testFailed))
    }
  }

  async function enable(webhook: Webhook) {
    try {
      await setWebhookActive(webhook.id, true)
      await refreshList()
      toast.success(fill(text.enabledToast, { name: webhook.name }))
    } catch (error) {
      toast.error(errorText(error, text.toggleFailed))
    }
  }

  const confirmText =
    pending?.kind === 'disable'
      ? { title: text.disableTitle, body: text.disableBody, label: text.disable, failed: text.toggleFailed }
      : pending?.kind === 'rotate'
        ? { title: text.rotateTitle, body: text.rotateBody, label: text.rotate, failed: text.rotateFailed }
        : { title: text.deleteTitle, body: text.deleteBody, label: text.delete, failed: text.deleteFailed }

  async function confirmPending() {
    if (!pending) return
    const { kind, webhook } = pending
    if (kind === 'disable') {
      await setWebhookActive(webhook.id, false)
      await refreshList()
      toast.success(fill(text.disabledToast, { name: webhook.name }))
    } else if (kind === 'rotate') {
      const rotated = await rotateWebhookSecret(webhook.id)
      await refreshList()
      setDialog({ webhook: null, rotated })
    } else {
      await deleteWebhook(webhook.id)
      if (selectedId === webhook.id) setSelectedId(null)
      await refreshList()
      toast.success(fill(text.deletedToast, { name: webhook.name }))
    }
  }

  return (
    <SettingsPage
      title={text.title}
      description={text.intro}
      actions={
        <Button onClick={() => setDialog({ webhook: null, rotated: null })}>
          <PlusIcon aria-hidden="true" />
          {text.create}
        </Button>
      }
    >
      {webhooks.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : webhooks.isError ? (
        <ErrorState error={webhooks.error} onRetry={() => void webhooks.refetch()} />
      ) : webhooks.data.length === 0 ? (
        <EmptyState icon={WebhookIcon} title={text.empty} />
      ) : (
        <Table aria-label={text.listLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{text.columns.name}</TableHead>
              <TableHead>{text.columns.events}</TableHead>
              <TableHead>{text.columns.status}</TableHead>
              <TableHead>{text.columns.lastDelivery}</TableHead>
              <TableHead className="text-right">{text.columns.actions}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {webhooks.data.map((webhook) => (
              <WebhookRow
                key={webhook.id}
                webhook={webhook}
                timeZone={timeZone}
                showingDeliveries={selectedId === webhook.id}
                onShowDeliveries={(item) => setSelectedId(selectedId === item.id ? null : item.id)}
                onEdit={(item) => setDialog({ webhook: item, rotated: null })}
                onTest={(item) => void sendTest(item)}
                onEnable={(item) => void enable(item)}
                onAsk={setPending}
              />
            ))}
          </TableBody>
        </Table>
      )}
      {selected ? <WebhookDeliveries webhook={selected} onClose={() => setSelectedId(null)} /> : null}
      <WebhookDialog
        open={dialog !== null}
        webhook={dialog?.webhook ?? null}
        rotated={dialog?.rotated ?? null}
        onClose={() => setDialog(null)}
      />
      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(open) => {
          if (!open) setPending(null)
        }}
        destructive={pending?.kind !== 'rotate'}
        title={confirmText.title}
        description={fill(confirmText.body, { name: pending?.webhook.name ?? '' })}
        confirmLabel={confirmText.label}
        failedTitle={confirmText.failed}
        onConfirm={confirmPending}
      />
    </SettingsPage>
  )
}

import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRoundIcon, PlusIcon } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
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
import { FieldGroup } from '@/components/ui/field'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { CheckboxGroup } from '@/features/users'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  type ApiClient,
  type ApiScope,
  apiClientQueries,
  type CreateApiClientInput,
  createApiClient,
  type NewApiClient,
  revokeApiClient,
} from '../api/api-client-queries'
import { type ApiClientFormValues, apiClientFormSchema } from '../schemas'
import { CopyableValue } from './copyable-value'

const text = copy.apiClients

function CreateForm({
  scopes,
  onCreated,
  onCancel,
}: {
  scopes: readonly ApiScope[]
  onCreated: (client: NewApiClient) => void
  onCancel: () => void
}) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const defaultValues: ApiClientFormValues = { name: '', scopes: [] }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: apiClientFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const parsed = apiClientFormSchema.parse(value)
        const created = await createApiClient({
          name: parsed.name,
          scopes: parsed.scopes as CreateApiClientInput['scopes'],
        })
        await client.invalidateQueries({ queryKey: queryKeys.apiClients.list(tenantId) })
        onCreated(created)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={text.createTitle}
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
              id="api-client-name"
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
        <form.Field name="scopes">
          {(field) => (
            <CheckboxGroup
              id="api-client-scopes"
              legend={text.scopes}
              description={text.scopesHint}
              options={scopes.map((scope) => ({
                value: scope.scope,
                label: `${scope.scope}: ${scope.description}`,
              }))}
              value={field.state.value}
              onChange={field.handleChange}
              errors={mergeMessages(field.state.meta.errors, server.fields.scopes)}
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
              {isSubmitting ? text.saving : text.save}
            </Button>
          </DialogFooter>
        )}
      </form.Subscribe>
    </form>
  )
}

/** The only view of a new client's secret. Closing the dialog drops it from memory. */
function SecretView({ created, onDone }: { created: NewApiClient; onDone: () => void }) {
  return (
    <div className="flex flex-col gap-4">
      <Alert>
        <KeyRoundIcon aria-hidden="true" />
        <AlertTitle>{text.secretTitle}</AlertTitle>
        <AlertDescription>{text.secretDescription}</AlertDescription>
      </Alert>
      <CopyableValue id="api-client-id" label={text.clientId} value={created.client_id} />
      <CopyableValue id="api-client-secret" label={text.clientSecret} value={created.client_secret} />
      <DialogFooter>
        <Button type="button" onClick={onDone}>
          {text.done}
        </Button>
      </DialogFooter>
    </div>
  )
}

function CreateApiClientDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const scopes = useQuery({ ...apiClientQueries.scopes(tenantId), enabled: open && tenantId !== '' })
  const [created, setCreated] = useState<NewApiClient | null>(null)
  const close = () => {
    setCreated(null)
    onClose()
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // The secret view closes only through its button, so the secret is not dismissed by accident.
        if (!next && created === null) close()
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{created ? created.name : text.createTitle}</DialogTitle>
          <DialogDescription>{created ? text.secretTitle : text.createDescription}</DialogDescription>
        </DialogHeader>
        {!open ? null : created ? (
          <SecretView created={created} onDone={close} />
        ) : scopes.isPending ? (
          <Skeleton className="h-48 w-full" />
        ) : scopes.isError ? (
          <ErrorState error={scopes.error} onRetry={() => void scopes.refetch()} />
        ) : (
          <CreateForm scopes={scopes.data} onCreated={setCreated} onCancel={close} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function ClientRow({
  client,
  timeZone,
  onRevoke,
}: {
  client: ApiClient
  timeZone: string
  onRevoke: (client: ApiClient) => void
}) {
  return (
    <TableRow>
      <TableCell className="font-medium">{client.name}</TableCell>
      <TableCell>
        <ul className="flex flex-wrap gap-1" aria-label={fill(text.scopesOf, { name: client.name })}>
          {client.scopes.map((scope) => (
            <li key={scope}>
              <Badge variant="outline" className="font-mono">
                {scope}
              </Badge>
            </li>
          ))}
        </ul>
      </TableCell>
      <TableCell>
        {client.revoked ? (
          <Badge variant="destructive">{text.revoked}</Badge>
        ) : (
          <Badge variant="secondary">{text.active}</Badge>
        )}
      </TableCell>
      <TableCell>{client.last_used_at ? formatInZone(client.last_used_at, timeZone) : text.never}</TableCell>
      <TableCell>{formatInZone(client.created_at, timeZone)}</TableCell>
      <TableCell className="text-right">
        {client.revoked ? null : (
          <Button
            size="sm"
            variant="destructive"
            aria-label={fill(text.revokeNamed, { name: client.name })}
            onClick={() => onRevoke(client)}
          >
            {text.revoke}
          </Button>
        )}
      </TableCell>
    </TableRow>
  )
}

/**
 * Settings → Developer → API clients (roadmap M3-04, docs/07-api/authentication.md §3): list, create
 * with scopes (secret shown once), revoke. Permission `integrations.manage`.
 */
export function ApiClientsSettings() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const allowed = useCan('integrations.manage')
  const queryClient = useQueryClient()
  const clients = useQuery({ ...apiClientQueries.list(tenantId), enabled: allowed && tenantId !== '' })
  const [creating, setCreating] = useState(false)
  const [revoking, setRevoking] = useState<ApiClient | null>(null)

  if (!allowed) return <ForbiddenState />

  return (
    <section className="space-y-6" aria-labelledby="settings-api-clients-heading">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="settings-api-clients-heading" className="text-lg font-semibold">
            {text.title}
          </h2>
          <p className="max-w-2xl text-sm text-muted-foreground">{text.intro}</p>
        </div>
        <Button onClick={() => setCreating(true)}>
          <PlusIcon aria-hidden="true" />
          {text.create}
        </Button>
      </div>
      {clients.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : clients.isError ? (
        <ErrorState error={clients.error} onRetry={() => void clients.refetch()} />
      ) : clients.data.length === 0 ? (
        <EmptyState icon={KeyRoundIcon} title={text.empty} />
      ) : (
        <Table aria-label={text.listLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{text.columns.name}</TableHead>
              <TableHead>{text.columns.scopes}</TableHead>
              <TableHead>{text.columns.status}</TableHead>
              <TableHead>{text.columns.lastUsed}</TableHead>
              <TableHead>{text.columns.created}</TableHead>
              <TableHead className="text-right">{text.columns.actions}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {clients.data.map((client) => (
              <ClientRow key={client.id} client={client} timeZone={timeZone} onRevoke={setRevoking} />
            ))}
          </TableBody>
        </Table>
      )}
      <CreateApiClientDialog open={creating} onClose={() => setCreating(false)} />
      <ConfirmDialog
        open={revoking !== null}
        onOpenChange={(open) => {
          if (!open) setRevoking(null)
        }}
        destructive
        title={text.revokeTitle}
        description={fill(text.revokeBody, { name: revoking?.name ?? '' })}
        confirmLabel={text.revoke}
        failedTitle={text.revokeFailed}
        onConfirm={async () => {
          if (!revoking) return
          await revokeApiClient(revoking.id)
          await queryClient.invalidateQueries({ queryKey: queryKeys.apiClients.list(tenantId) })
          toast.success(fill(text.revokedToast, { name: revoking.name }))
        }}
      />
    </section>
  )
}

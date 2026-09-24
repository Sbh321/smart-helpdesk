import { revalidateLogic, useForm, useStore } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { CopyIcon } from 'lucide-react'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { SaveBar, UnsavedChangesGuard } from '@/components/shared/save-bar'
import { SettingsPage } from '@/components/shared/settings-page'
import { TextField } from '@/components/shared/text-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  type DnsRecord,
  type EmailSettings as EmailSettingsData,
  saveEmailSettings,
  useEmailSettings,
} from '../api/email-settings-queries'
import { formatMailbox, senderFormSchema } from '../schemas'
import { InboundLog, InboundRules } from './inbound-email'

const text = copy.mailSettings

async function copyToClipboard(label: string, value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success(fill(text.copied, { name: label }))
  } catch {
    toast.error(text.copyFailed)
  }
}

function CopyButton({ label, value }: { label: string; value: string }) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      aria-label={fill(text.copyNamed, { name: label })}
      onClick={() => void copyToClipboard(label, value)}
    >
      <CopyIcon aria-hidden="true" />
      {text.copy}
    </Button>
  )
}

function SenderForm({ tenantId, settings }: { tenantId: string; settings: EmailSettingsData }) {
  const client = useQueryClient()
  const server = useServerErrors()
  const form = useForm({
    defaultValues: { sender_name: settings.sender_name ?? '' },
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: senderFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      const name = value.sender_name.trim()
      try {
        await saveEmailSettings(client, tenantId, { sender_name: name === '' ? null : name })
        form.reset(value)
        toast.success(text.saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })
  const typed = useStore(form.store, (state) => state.values.sender_name.trim())
  const preview = formatMailbox({
    name: typed === '' ? settings.default_sender_name : typed,
    address: settings.from.address,
  })

  return (
    <form
      noValidate
      aria-labelledby="settings-email-sender-heading"
      className="flex max-w-xl flex-col gap-4 rounded-lg border border-border p-4"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      <h3 id="settings-email-sender-heading" className="font-medium">
        {text.senderTitle}
      </h3>
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <form.Field name="sender_name">
        {(field) => (
          <TextField
            id="settings-email-sender-name"
            label={text.senderName}
            description={fill(text.senderNameHelp, { default: settings.default_sender_name })}
            placeholder={settings.default_sender_name}
            autoComplete="off"
            value={field.state.value}
            onValueChange={field.handleChange}
            onBlur={field.handleBlur}
            errors={mergeMessages(field.state.meta.errors, server.fields.sender_name)}
          />
        )}
      </form.Field>
      <p className="text-sm">
        <span className="text-muted-foreground">{text.preview}: </span>
        <span className="break-all font-mono text-xs" data-testid="sender-preview">
          {preview}
        </span>
      </p>
      <form.Subscribe
        selector={(state) => ({ dirty: !state.isDefaultValue, submitting: state.isSubmitting })}
      >
        {({ dirty, submitting }) => (
          <>
            <SaveBar
              dirty={dirty}
              submitting={submitting}
              saveLabel={text.save}
              onDiscard={() => form.reset()}
            />
            <UnsavedChangesGuard dirty={dirty} />
          </>
        )}
      </form.Subscribe>
    </form>
  )
}

function AddressRow({
  term,
  value,
  help,
  copyable = false,
}: {
  term: string
  value: string
  help?: string
  copyable?: boolean
}) {
  return (
    // dt and dd are direct children of the row: a definition list allows one wrapping div, no more.
    <div className="grid gap-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-x-4">
      <dt className="text-sm font-medium sm:col-start-1">{term}</dt>
      <dd className="break-all font-mono text-xs sm:col-start-1">{value}</dd>
      {help ? <dd className="mt-1 text-sm text-muted-foreground sm:col-start-1">{help}</dd> : null}
      {copyable ? (
        <dd className="sm:col-start-2 sm:row-span-3 sm:row-start-1 sm:self-start">
          <CopyButton label={term} value={value} />
        </dd>
      ) : null}
    </div>
  )
}

function Addresses({ settings }: { settings: EmailSettingsData }) {
  return (
    <section
      aria-labelledby="settings-email-addresses-heading"
      className="max-w-2xl rounded-lg border border-border p-4"
    >
      <h3 id="settings-email-addresses-heading" className="mb-3 font-medium">
        {text.addressesTitle}
      </h3>
      <dl className="divide-y divide-border">
        <AddressRow
          term={text.intakeAddress}
          value={settings.intake_address}
          help={text.intakeHelp}
          copyable
        />
        <AddressRow term={text.replyTo} value={settings.reply_to_pattern} help={text.replyToHelp} />
        <AddressRow term={text.platformFrom} value={formatMailbox(settings.platform_from)} />
      </dl>
    </section>
  )
}

function DnsRecords({ domain, records }: { domain: string; records: DnsRecord[] }) {
  return (
    <section aria-labelledby="settings-email-dns-heading" className="space-y-3">
      <h3 id="settings-email-dns-heading" className="font-medium">
        {text.dnsTitle}
      </h3>
      <p className="max-w-2xl text-sm text-muted-foreground">{fill(text.dnsIntro, { domain })}</p>
      <div className="rounded-lg border border-border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead scope="col">{text.dnsType}</TableHead>
              <TableHead scope="col">{text.dnsName}</TableHead>
              <TableHead scope="col">{text.dnsValue}</TableHead>
              <TableHead scope="col">
                <span className="sr-only">{text.copy}</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {records.map((record) => (
              <TableRow key={`${record.type}-${record.name}`}>
                <TableCell className="align-top font-mono text-xs">{record.type}</TableCell>
                <TableCell className="max-w-48 align-top font-mono text-xs break-all whitespace-normal">
                  {record.name}
                </TableCell>
                <TableCell className="min-w-64 align-top whitespace-normal">
                  {record.ready ? (
                    <span className="font-mono text-xs break-all">{record.value}</span>
                  ) : (
                    <span className="flex flex-col items-start gap-1 text-sm">
                      <Badge variant="outline">{text.dnsNotReady}</Badge>
                      {record.value}
                    </span>
                  )}
                  <span className="mt-1 block text-sm text-muted-foreground">{record.purpose}</span>
                </TableCell>
                <TableCell className="align-top">
                  {record.ready ? (
                    <CopyButton label={`${record.type} ${record.name}`} value={record.value} />
                  ) : null}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </section>
  )
}

/**
 * Settings → Email: sender name, the workspace's addresses, the inbound rules and log (M3-19) and the
 * mail domain's DNS records (`mail.manage`).
 */
export function EmailSettings() {
  const { allowed, tenantId, query } = useEmailSettings()
  if (!allowed) return <ForbiddenState />

  return (
    <SettingsPage title={text.title} description={text.intro}>
      {query.isPending ? (
        <Skeleton className="h-32 w-full max-w-2xl" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : (
        <>
          <SenderForm tenantId={tenantId} settings={query.data} />
          <Addresses settings={query.data} />
          <InboundRules tenantId={tenantId} settings={query.data} />
          <InboundLog />
          <DnsRecords domain={query.data.mail_domain} records={query.data.dns_records} />
        </>
      )}
    </SettingsPage>
  )
}

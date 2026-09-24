import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { InboxIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import {
  DataTable,
  dataTableColumnHelper,
  FilterBar,
  type FilterOption,
  MultiSelectFilter,
} from '@/components/shared/data-table'
import { EmptyState } from '@/components/shared/empty-state'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { Switch } from '@/components/ui/switch'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { useListParams } from '@/lib/list-params'
import { type EmailSettings, saveEmailSettings } from '../api/email-settings-queries'
import {
  INBOUND_STATES,
  type InboundEmail,
  type InboundState,
  inboundApiQuery,
  inboundEmailQueries,
  inboundListSchema,
} from '../api/inbound-email-queries'

const text = copy.mailSettings.inbound
const helper = dataTableColumnHelper<InboundEmail>()

const STATE_OPTIONS: FilterOption[] = INBOUND_STATES.map((state) => ({
  value: state,
  label: text.states[state] ?? state,
}))

const STATE_VARIANT: Record<InboundState, 'default' | 'secondary' | 'outline' | 'destructive'> = {
  comment: 'secondary',
  ticket: 'default',
  ignored: 'outline',
  unrouted: 'outline',
  rejected: 'destructive',
}

type Flag = 'create_contacts' | 'match_organisation_domain'

/** One inbound rule that saves as soon as it is switched; back to the stored value on failure. */
function InboundSwitch({
  id,
  flag,
  label,
  description,
  tenantId,
  settings,
}: {
  id: string
  flag: Flag
  label: string
  description: string
  tenantId: string
  settings: EmailSettings
}) {
  const client = useQueryClient()
  const [pending, setPending] = useState<boolean | null>(null)
  const [error, setError] = useState<string | null>(null)

  const change = async (next: boolean) => {
    setPending(next)
    setError(null)
    try {
      await saveEmailSettings(client, tenantId, { [flag]: next })
      toast.success(text.saved)
    } catch (cause) {
      const detail = isApiError(cause) ? (cause.detail ?? cause.title) : null
      setError(detail ? `${text.failed}: ${detail}` : text.failed)
    } finally {
      setPending(null)
    }
  }

  return (
    <div className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <p id={`${id}-label`} className="text-sm font-medium">
            {label}
          </p>
          <p id={`${id}-description`} className="text-sm text-muted-foreground">
            {description}
          </p>
        </div>
        <Switch
          id={id}
          aria-labelledby={`${id}-label`}
          aria-describedby={`${id}-description`}
          checked={pending ?? settings[flag]}
          disabled={pending !== null}
          onCheckedChange={(next) => void change(next)}
        />
      </div>
      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  )
}

/** Settings → Email: how the helpdesk treats mail from senders it does not know yet (M3-19). */
export function InboundRules({ tenantId, settings }: { tenantId: string; settings: EmailSettings }) {
  return (
    <section
      aria-labelledby="settings-email-inbound-heading"
      className="max-w-2xl space-y-3 rounded-lg border border-border p-4"
    >
      <div className="space-y-1">
        <h3 id="settings-email-inbound-heading" className="font-medium">
          {text.rulesTitle}
        </h3>
        <p className="text-sm text-muted-foreground">{text.rulesIntro}</p>
      </div>
      <div className="divide-y divide-border">
        <InboundSwitch
          id="settings-email-create-contacts"
          flag="create_contacts"
          label={text.createContacts}
          description={text.createContactsHelp}
          tenantId={tenantId}
          settings={settings}
        />
        <InboundSwitch
          id="settings-email-match-organisation"
          flag="match_organisation_domain"
          label={text.matchOrganisation}
          description={text.matchOrganisationHelp}
          tenantId={tenantId}
          settings={settings}
        />
      </div>
    </section>
  )
}

function inboundColumns(timeZone: string, workspace: string) {
  return helper.columns([
    helper.accessor('processed_at', {
      enableHiding: false,
      meta: { label: text.columns.received, className: 'whitespace-nowrap tabular-nums' },
      cell: (info) => (
        <time dateTime={info.getValue()}>{formatInZone(info.getValue(), timeZone, 'd MMM yyyy, HH:mm')}</time>
      ),
    }),
    helper.accessor('from', {
      enableHiding: false,
      meta: { label: text.columns.from },
      cell: (info) => {
        const from = info.getValue()
        return (
          <span className="flex flex-col">
            <span className="font-medium">{from.name ?? from.address ?? '—'}</span>
            {from.name && from.address ? (
              <span className="text-xs break-all text-muted-foreground">{from.address}</span>
            ) : null}
          </span>
        )
      },
    }),
    helper.accessor('subject', {
      enableHiding: false,
      meta: { label: text.columns.subject, className: 'whitespace-normal' },
      cell: (info) => {
        const email = info.row.original
        const files = email.attachments.length
        return (
          <span className="flex max-w-md flex-col">
            <span className="break-words">{info.getValue() === '' ? text.noSubject : info.getValue()}</span>
            {files > 0 ? (
              <span className="text-xs text-muted-foreground">
                {files === 1 ? text.attachmentsOne : fill(text.attachments, { count: files })}
              </span>
            ) : null}
          </span>
        )
      },
    }),
    helper.accessor('state', {
      enableHiding: false,
      meta: { label: text.columns.state, className: 'whitespace-normal' },
      cell: (info) => {
        const email = info.row.original
        return (
          <span className="flex flex-col items-start gap-1">
            <Badge variant={STATE_VARIANT[email.state]}>{text.states[email.state] ?? email.state}</Badge>
            {email.reason ? (
              <span className="text-xs text-muted-foreground">
                {text.reasons[email.reason] ?? email.reason}
              </span>
            ) : null}
          </span>
        )
      },
    }),
    helper.accessor('ticket', {
      enableHiding: false,
      meta: { label: text.columns.ticket, className: 'whitespace-normal' },
      cell: (info) => {
        const ticket = info.getValue()
        if (ticket === null) return <span className="text-muted-foreground">—</span>
        return (
          <Link
            to="/$workspace/tickets/$ticketId"
            params={{ workspace, ticketId: ticket.id }}
            className="font-medium underline-offset-4 hover:underline"
          >
            #{ticket.number}
            <span className="sr-only"> {ticket.title}</span>
          </Link>
        )
      },
    }),
  ])
}

/**
 * Settings → Email: the inbound log (`GET /v1/inbound-emails`, `mail.manage`), newest first, filtered
 * by result in the URL, with "Load older mail" for the cursor feed.
 */
export function InboundLog() {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const workspace = session?.tenant.slug ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const list = useListParams(inboundListSchema)
  const messages = useInfiniteQuery({
    ...inboundEmailQueries.list(tenantId, inboundApiQuery(list.apiQuery)),
    enabled: tenantId !== '',
  })
  const columns = useMemo(() => inboundColumns(timeZone, workspace), [timeZone, workspace])
  const rows = messages.data?.pages.flatMap((page) => page.data)

  const emptyState =
    list.activeFilterCount > 0 ? (
      <EmptyState
        icon={InboxIcon}
        title={text.noMatchesTitle}
        description={text.noMatchesBody}
        action={
          <Button type="button" variant="outline" onClick={list.clearFilters}>
            {copy.filters.clear}
          </Button>
        }
      />
    ) : (
      <EmptyState icon={InboxIcon} title={text.emptyTitle} description={text.emptyBody} />
    )

  return (
    <section aria-labelledby="settings-email-log-heading" className="space-y-3">
      <div className="space-y-1">
        <h3 id="settings-email-log-heading" className="font-medium">
          {text.logTitle}
        </h3>
        <p className="max-w-2xl text-sm text-muted-foreground">{text.logIntro}</p>
      </div>
      <DataTable
        id="settings-email-log"
        label={text.tableLabel}
        columns={columns}
        data={rows}
        rowCount={undefined}
        state={{ page: 1, per_page: list.params.per_page, sort: list.params.sort }}
        onStateChange={list.update}
        getRowId={(email) => email.id}
        isFetching={messages.isFetching && !messages.isFetchingNextPage && rows !== undefined}
        error={messages.error}
        onRetry={() => void messages.refetch()}
        emptyState={emptyState}
        toolbar={
          <FilterBar activeCount={list.activeFilterCount} onClear={list.clearFilters}>
            <MultiSelectFilter
              label={text.filterState}
              options={STATE_OPTIONS}
              value={list.params.filters.state ?? []}
              onChange={(value) => list.setFilter('state', value)}
            />
          </FilterBar>
        }
      />
      {messages.hasNextPage ? (
        <Button
          type="button"
          variant="outline"
          disabled={messages.isFetchingNextPage}
          onClick={() => void messages.fetchNextPage()}
        >
          {messages.isFetchingNextPage ? text.loadingOlder : text.loadOlder}
        </Button>
      ) : null}
    </section>
  )
}

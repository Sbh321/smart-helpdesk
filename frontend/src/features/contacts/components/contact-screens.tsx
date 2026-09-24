import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArchiveIcon, ArchiveRestoreIcon, PlusIcon } from 'lucide-react'
import { BackLink } from '@/components/shared/back-link'
import { type DetailTab, DetailTabs } from '@/components/shared/detail-tabs'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { RecordLayout } from '@/components/shared/record-layout'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import { archiveContact, type Contact, contactQueries, unarchiveContact } from '../api/contact-queries'
import { ContactForm } from './contact-form'
import { ContactList } from './contact-list'
import { useTenantId } from './use-pickers'

/** `/$workspace/contacts`: the list, with "New contact" for `contacts.manage`. */
export function ContactsScreen({ workspace }: { workspace: string }) {
  const allowed = useCan('contacts.view')
  const canManage = useCan('contacts.manage')
  return (
    <>
      <PageHeader
        title={copy.contacts.title}
        description={copy.contacts.description}
        actions={
          allowed && canManage ? (
            <Link to="/$workspace/contacts/new" params={{ workspace }} className={buttonVariants()}>
              <PlusIcon aria-hidden="true" />
              {copy.contacts.list.create}
            </Link>
          ) : null
        }
      />
      {allowed ? <ContactList workspace={workspace} /> : <ForbiddenState />}
    </>
  )
}

/** `/$workspace/contacts/new`. */
export function NewContactScreen({ workspace }: { workspace: string }) {
  const canManage = useCan('contacts.manage')
  const navigate = useNavigate()
  return (
    <>
      <PageHeader
        eyebrow={
          <BackLink workspace={workspace} to="/$workspace/contacts" label={copy.contacts.detail.back} />
        }
        title={copy.contacts.newTitle}
      />
      {canManage ? (
        <ContactForm
          onSaved={(contact) =>
            void navigate({
              to: '/$workspace/contacts/$contactId',
              params: { workspace, contactId: contact.id },
            })
          }
        />
      ) : (
        <ForbiddenState />
      )}
    </>
  )
}

function ContactFacts({ contact, timeZone }: { contact: Contact; timeZone: string }) {
  const rows: Array<[string, string]> = [
    [copy.contacts.form.email, contact.email],
    [copy.contacts.form.phone, contact.phone ?? copy.contacts.none],
    [copy.contacts.form.organization, contact.organization?.name ?? copy.contacts.none],
    [copy.contacts.form.tags, contact.tags.map((tag) => tag.name).join(', ') || copy.contacts.none],
    [copy.contacts.columns.createdAt, formatInZone(contact.created_at, timeZone, 'd MMM yyyy')],
  ]
  return (
    <dl className="grid max-w-xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
      {rows.map(([term, value]) => (
        <div key={term} className="contents">
          <dt className="text-muted-foreground">{term}</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  )
}

/**
 * `/$workspace/contacts/$contactId`: edit form (or read-only facts) plus archive / restore; the route
 * adds the Overview and History tabs (M3-21) through `tabs`.
 */
export function ContactScreen({
  workspace,
  contactId,
  tabs = [],
}: {
  workspace: string
  contactId: string
  tabs?: readonly DetailTab[]
}) {
  const allowed = useCan('contacts.view')
  const canManage = useCan('contacts.manage')
  const tenantId = useTenantId()
  const timeZone = useSession().session?.tenant.timezone ?? 'UTC'
  const queryClient = useQueryClient()
  const contact = useQuery({
    ...contactQueries.detail(tenantId, contactId),
    enabled: allowed && tenantId !== '',
  })

  const archive = useMutation({
    mutationFn: (archived: boolean) => (archived ? unarchiveContact(contactId) : archiveContact(contactId)),
    onSuccess: async (saved, wasArchived) => {
      queryClient.setQueryData(queryKeys.contacts.detail(tenantId, saved.id), saved)
      await queryClient.invalidateQueries({ queryKey: queryKeys.contacts.all(tenantId) })
      toast.success(
        fill(wasArchived ? copy.contacts.detail.unarchivedToast : copy.contacts.detail.archivedToast, {
          name: saved.name,
        }),
      )
    },
  })

  const back = <BackLink workspace={workspace} to="/$workspace/contacts" label={copy.contacts.detail.back} />
  if (!allowed) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.contacts.editTitle} />
        <ForbiddenState />
      </>
    )
  }
  if (contact.isPending) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.contacts.editTitle} />
        <div className="flex max-w-xl flex-col gap-3" aria-busy="true">
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-2/3" />
        </div>
      </>
    )
  }
  if (contact.isError) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.contacts.editTitle} />
        {isApiError(contact.error) && contact.error.status === 404 ? (
          <NotFoundState action={back} />
        ) : (
          <ErrorState error={contact.error} onRetry={() => void contact.refetch()} />
        )}
      </>
    )
  }

  const current = contact.data
  const archived = current.archived_at !== null
  return (
    <RecordLayout
      eyebrow={back}
      kind={copy.entity360.entities.contacts}
      title={current.name}
      description={current.email}
      timeZone={timeZone}
      history={tabs.find((tab) => tab.value === 'history')?.content}
      badges={archived ? <Badge variant="secondary">{copy.contacts.list.archived}</Badge> : null}
      actions={
        canManage ? (
          <Button
            type="button"
            variant="outline"
            disabled={archive.isPending}
            onClick={() => archive.mutate(archived)}
          >
            {archived ? <ArchiveRestoreIcon aria-hidden="true" /> : <ArchiveIcon aria-hidden="true" />}
            {archived ? copy.contacts.detail.unarchive : copy.contacts.detail.archive}
          </Button>
        ) : null
      }
    >
      {archive.isError ? <FormErrorBanner title={copy.contacts.form.failed} error={archive.error} /> : null}
      {archived && current.archived_at ? (
        <p className="text-sm text-muted-foreground">
          {fill(copy.contacts.detail.archived, {
            date: formatInZone(current.archived_at, timeZone, 'd MMM yyyy'),
          })}
        </p>
      ) : null}
      <DetailTabs
        label={copy.entity360.tabs}
        detailsLabel={copy.entity360.details}
        tabs={tabs}
        details={
          canManage ? (
            <ContactForm key={current.id} contact={current} onSaved={() => undefined} />
          ) : (
            <>
              <p className="text-sm text-muted-foreground">{copy.contacts.detail.readOnly}</p>
              <ContactFacts contact={current} timeZone={timeZone} />
            </>
          )
        }
      />
    </RecordLayout>
  )
}

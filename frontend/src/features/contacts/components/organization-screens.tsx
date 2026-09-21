import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { PlusIcon } from 'lucide-react'
import { BackLink } from '@/components/shared/back-link'
import { type DetailTab, DetailTabs } from '@/components/shared/detail-tabs'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { useCan } from '@/lib/auth'
import { organizationQueries } from '../api/organization-queries'
import { OrganizationForm } from './organization-form'
import { OrganizationList, tierLabel } from './organization-list'
import { useTenantId } from './use-pickers'

/** `/$workspace/organizations`. */
export function OrganizationsScreen({ workspace }: { workspace: string }) {
  const allowed = useCan('contacts.view')
  const canManage = useCan('contacts.manage')
  return (
    <>
      <PageHeader
        title={copy.organizations.title}
        description={copy.organizations.description}
        actions={
          allowed && canManage ? (
            <Link to="/$workspace/organizations/new" params={{ workspace }} className={buttonVariants()}>
              <PlusIcon aria-hidden="true" />
              {copy.organizations.list.create}
            </Link>
          ) : null
        }
      />
      {allowed ? <OrganizationList workspace={workspace} /> : <ForbiddenState />}
    </>
  )
}

/** `/$workspace/organizations/new`. */
export function NewOrganizationScreen({ workspace }: { workspace: string }) {
  const canManage = useCan('contacts.manage')
  const navigate = useNavigate()
  return (
    <>
      <PageHeader
        eyebrow={
          <BackLink
            workspace={workspace}
            to="/$workspace/organizations"
            label={copy.organizations.detail.back}
          />
        }
        title={copy.organizations.newTitle}
      />
      {canManage ? (
        <OrganizationForm
          onSaved={(organization) =>
            void navigate({
              to: '/$workspace/organizations/$organizationId',
              params: { workspace, organizationId: organization.id },
            })
          }
        />
      ) : (
        <ForbiddenState />
      )}
    </>
  )
}

/** `/$workspace/organizations/$organizationId`: the edit form, or the facts without `contacts.manage`. */
export function OrganizationScreen({
  workspace,
  organizationId,
  tabs = [],
}: {
  workspace: string
  organizationId: string
  /** Overview and History (M3-21), added by the route. */
  tabs?: readonly DetailTab[]
}) {
  const allowed = useCan('contacts.view')
  const canManage = useCan('contacts.manage')
  const tenantId = useTenantId()
  const organization = useQuery({
    ...organizationQueries.detail(tenantId, organizationId),
    enabled: allowed && tenantId !== '',
  })
  const back = (
    <BackLink workspace={workspace} to="/$workspace/organizations" label={copy.organizations.detail.back} />
  )

  if (!allowed) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.organizations.editTitle} />
        <ForbiddenState />
      </>
    )
  }
  if (organization.isPending) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.organizations.editTitle} />
        <div className="flex max-w-xl flex-col gap-3" aria-busy="true">
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-2/3" />
        </div>
      </>
    )
  }
  if (organization.isError) {
    return (
      <>
        <PageHeader eyebrow={back} title={copy.organizations.editTitle} />
        {isApiError(organization.error) && organization.error.status === 404 ? (
          <NotFoundState action={back} />
        ) : (
          <ErrorState error={organization.error} onRetry={() => void organization.refetch()} />
        )}
      </>
    )
  }

  const current = organization.data
  return (
    <>
      <PageHeader eyebrow={back} title={current.name} description={tierLabel(current.tier)} />
      <DetailTabs
        label={copy.entity360.tabs}
        detailsLabel={copy.entity360.details}
        tabs={tabs}
        details={
          canManage ? (
            <OrganizationForm key={current.id} organization={current} onSaved={() => undefined} />
          ) : (
            <>
              <p className="text-sm text-muted-foreground">{copy.organizations.detail.readOnly}</p>
              <dl className="grid max-w-xl grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                <dt className="text-muted-foreground">{copy.organizations.form.domain}</dt>
                <dd>{current.domain ?? copy.contacts.none}</dd>
                <dt className="text-muted-foreground">{copy.organizations.columns.contacts}</dt>
                <dd>{current.contacts_count}</dd>
                <dt className="text-muted-foreground">{copy.organizations.form.tags}</dt>
                <dd>{current.tags.map((tag) => tag.name).join(', ') || copy.contacts.none}</dd>
              </dl>
            </>
          )
        }
      />
    </>
  )
}

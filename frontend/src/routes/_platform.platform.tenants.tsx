import { useQuery } from '@tanstack/react-query'
import { createFileRoute } from '@tanstack/react-router'
import { ArchiveIcon, Building2Icon, CircleCheckIcon, CircleHelpIcon, PauseCircleIcon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { PageHeader } from '@/components/shared/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy } from '@/copy/en'
import { platformTenantsQuery } from '@/features/platform'
import { formatInZone } from '@/lib/datetime/format'

/**
 * Read-only list of the workspaces (`GET /platform-api/tenants`, first page of 25). Status and plan read
 * as words, the status with its own icon (not colour alone), as everywhere since the redesign (M4, G4).
 */
const STATUS_ICONS = { active: CircleCheckIcon, suspended: PauseCircleIcon, archived: ArchiveIcon } as const
const STATUS_TONES = {
  active: 'text-success',
  suspended: 'text-warning',
  archived: 'text-muted-foreground',
} as const

function TenantStatus({ status }: { status: string }) {
  const labels = copy.platform.statuses as Record<string, string>
  const known = status in STATUS_ICONS ? (status as keyof typeof STATUS_ICONS) : null
  const Icon = known ? STATUS_ICONS[known] : CircleHelpIcon
  return (
    <span className={`inline-flex items-center gap-1.5 ${known ? STATUS_TONES[known] : ''}`}>
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {labels[status] ?? status}
    </span>
  )
}
export const Route = createFileRoute('/_platform/platform/tenants')({
  component: TenantsPage,
})

function TenantsPage() {
  const { data: tenants, isPending, error, refetch } = useQuery(platformTenantsQuery())
  const { columns } = copy.platform

  return (
    <>
      <PageHeader title={copy.platform.title} description={copy.platform.description} />
      {isPending ? (
        // The shape of the rows it will show, with its words for screen readers (M4-13).
        <div className="flex flex-col gap-2" aria-busy="true">
          <p role="status" className="sr-only">
            {copy.platform.loading}
          </p>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : tenants.length === 0 ? (
        <EmptyState
          icon={Building2Icon}
          title={copy.platform.emptyTitle}
          description={copy.platform.emptyBody}
        />
      ) : (
        <Table aria-label={copy.platform.tableLabel}>
          <TableHeader>
            <TableRow>
              <TableHead>{columns.name}</TableHead>
              <TableHead>{columns.slug}</TableHead>
              <TableHead>{columns.status}</TableHead>
              <TableHead>{columns.plan}</TableHead>
              <TableHead>{columns.created}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {tenants.map((tenant) => (
              <TableRow key={tenant.id}>
                <TableCell className="font-medium">{tenant.name}</TableCell>
                <TableCell className="font-mono text-muted-foreground">{tenant.slug}</TableCell>
                <TableCell>
                  <TenantStatus status={tenant.status} />
                </TableCell>
                <TableCell>
                  {(copy.platform.plans as Record<string, string>)[tenant.plan] ?? tenant.plan}
                </TableCell>
                <TableCell className="tabular-nums">
                  {tenant.created_at
                    ? formatInZone(tenant.created_at, tenant.timezone, 'd MMM yyyy')
                    : copy.contacts.none}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </>
  )
}

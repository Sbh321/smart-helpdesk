import { useQuery } from '@tanstack/react-query'
import { createFileRoute } from '@tanstack/react-router'
import { Building2Icon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { PageHeader } from '@/components/shared/page-header'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy } from '@/copy/en'
import { platformTenantsQuery } from '@/features/platform'
import { formatInZone } from '@/lib/datetime/format'

/** Read-only list of the workspaces (`GET /platform-api/tenants`, first page of 25). */
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
        <Skeleton className="h-40 w-full" />
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : tenants.length === 0 ? (
        <EmptyState
          icon={Building2Icon}
          title={copy.platform.emptyTitle}
          description={copy.platform.emptyBody}
        />
      ) : (
        <Table>
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
                <TableCell>{tenant.slug}</TableCell>
                <TableCell>
                  <Badge variant={tenant.status === 'active' ? 'secondary' : 'destructive'}>
                    {tenant.status}
                  </Badge>
                </TableCell>
                <TableCell>{tenant.plan}</TableCell>
                <TableCell>
                  {tenant.created_at ? formatInZone(tenant.created_at, tenant.timezone, 'd MMM yyyy') : ''}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </>
  )
}

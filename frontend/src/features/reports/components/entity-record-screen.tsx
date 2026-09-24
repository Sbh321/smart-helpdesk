import { useQuery } from '@tanstack/react-query'
import { BackLink } from '@/components/shared/back-link'
import { DetailTabs } from '@/components/shared/detail-tabs'
import { ErrorState } from '@/components/shared/error-state'
import { ForbiddenState } from '@/components/shared/forbidden-state'
import { NotFoundState } from '@/components/shared/not-found-state'
import { PageHeader } from '@/components/shared/page-header'
import { RecordLayout } from '@/components/shared/record-layout'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { useCan, useSession } from '@/lib/auth'
import { entityQueries } from '../api/entity-queries'
import { OVERVIEW_PERMISSION } from '../entity-history'
import { useEntityTabs } from './entity-tabs'

type RecordEntity = 'agents' | 'teams' | 'categories'

const SETTINGS: Record<
  RecordEntity,
  '/$workspace/settings/agents' | '/$workspace/settings/teams' | '/$workspace/settings/categories'
> = {
  agents: '/$workspace/settings/agents',
  teams: '/$workspace/settings/teams',
  categories: '/$workspace/settings/categories',
}

/**
 * The read-only page of an Agent, Team or Category (roadmap M3-21): these are edited in Settings, so
 * the page is the record's Overview and History, linked from the settings lists and from report
 * drill-downs. The title comes from the overview, which the Overview tab shares from the cache.
 */
export function EntityRecordScreen({
  entity,
  id,
  workspace,
}: {
  entity: RecordEntity
  id: string
  workspace: string
}) {
  const text = copy.entity360
  const allowed = useCan(OVERVIEW_PERMISSION[entity])
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const tabs = useEntityTabs(entity, id, workspace)
  const overview = useQuery({
    ...entityQueries.overview(tenantId, entity, id),
    enabled: allowed && tenantId !== '',
  })
  const back = <BackLink workspace={workspace} to={SETTINGS[entity]} label={text.backTo[entity]} />
  const fallbackTitle = text.detailTitles[entity]

  if (!allowed) {
    return (
      <>
        <PageHeader eyebrow={back} title={fallbackTitle} />
        <ForbiddenState />
      </>
    )
  }
  if (overview.isPending) {
    return (
      <>
        <PageHeader eyebrow={back} title={fallbackTitle} />
        <Skeleton className="h-40 w-full" aria-busy="true" />
      </>
    )
  }
  if (overview.isError) {
    return (
      <>
        <PageHeader eyebrow={back} title={fallbackTitle} />
        {isApiError(overview.error) && overview.error.status === 404 ? (
          <NotFoundState action={back} />
        ) : (
          <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />
        )}
      </>
    )
  }

  return (
    <RecordLayout
      eyebrow={back}
      kind={text.entities[entity]}
      title={overview.data.title}
      description={text.readOnlyDetail}
      timeZone={timeZone}
      history={tabs.find((tab) => tab.value === 'history')?.content}
    >
      <DetailTabs label={text.tabs} tabs={tabs} />
    </RecordLayout>
  )
}

import { lazy, Suspense } from 'react'
import type { DetailTab } from '@/components/shared/detail-tabs'
import { Skeleton } from '@/components/ui/skeleton'
import { copy } from '@/copy/en'
import { permissionsOf, useSession } from '@/lib/auth'
import type { OverviewEntity } from '../api/entity-queries'
import { canViewHistory, OVERVIEW_PERMISSION } from '../entity-history'
import { EntityHistoryPanel } from './entity-history-panel'

// The overview draws charts (Recharts, ~400 kB): loaded when the tab is first opened.
const EntityOverviewPanel = lazy(() => import('./entity-overview-panel'))

function OverviewFallback() {
  return <Skeleton className="h-40 w-full" aria-busy="true" />
}

/**
 * The Overview and History sections of a record page (roadmap M3-21), for the routes to pass into
 * the owning feature's screen. A section the session may not read is left out rather than shown
 * forbidden: History needs what the history API needs (`canViewHistory`).
 */
export function useEntityTabs(entity: OverviewEntity, id: string, workspace: string): DetailTab[] {
  const permissions = permissionsOf(useSession().session)
  const tabs: DetailTab[] = []
  if (permissions.includes(OVERVIEW_PERMISSION[entity])) {
    tabs.push({
      value: 'overview',
      label: copy.entity360.overview,
      content: (
        <Suspense fallback={<OverviewFallback />}>
          <EntityOverviewPanel entity={entity} id={id} workspace={workspace} />
        </Suspense>
      ),
    })
  }
  if (canViewHistory(entity, permissions)) {
    tabs.push({
      value: 'history',
      label: copy.entity360.history,
      content: <EntityHistoryPanel entity={entity} id={id} />,
    })
  }
  return tabs
}

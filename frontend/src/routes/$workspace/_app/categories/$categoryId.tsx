import { createFileRoute } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { EntityRecordScreen } from '@/features/reports'
import { recordSearchSchema } from '@/lib/record-view'

/** A Category's read-only page: Overview and History (roadmap M3-21). */
export const Route = createFileRoute('/$workspace/_app/categories/$categoryId')({
  validateSearch: recordSearchSchema,
  component: CategoryPage,
  staticData: { crumb: copy.entity360.detailTitles.categories },
})

function CategoryPage() {
  const { workspace, categoryId } = Route.useParams()
  return <EntityRecordScreen entity="categories" id={categoryId} workspace={workspace} />
}

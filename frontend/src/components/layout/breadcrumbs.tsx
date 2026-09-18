import { Link, useMatches } from '@tanstack/react-router'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { copy } from '@/copy/en'

/**
 * Trail for the workspace shell. The leaf comes from the matched route's `staticData.crumb` (declared in
 * `src/app/router.tsx`), so a route names its own crumb and the two cannot drift. The workspace home is
 * always the first step; the current page is text, not a link.
 */
export function Breadcrumbs({ workspace }: { workspace: string }) {
  const matches = useMatches()
  const leaf = matches
    .map((match) => match.staticData.crumb)
    .filter((crumb): crumb is string => typeof crumb === 'string')
    .at(-1)

  return (
    <Breadcrumb aria-label={copy.shell.breadcrumbs}>
      <BreadcrumbList>
        <BreadcrumbItem>
          {leaf === undefined ? (
            <BreadcrumbPage>{copy.nav.dashboard}</BreadcrumbPage>
          ) : (
            <BreadcrumbLink render={<Link to="/$workspace" params={{ workspace }} />}>
              {copy.nav.dashboard}
            </BreadcrumbLink>
          )}
        </BreadcrumbItem>
        {leaf === undefined ? null : (
          <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage>{leaf}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  )
}

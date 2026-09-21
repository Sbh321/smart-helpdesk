import { Link, useParams } from '@tanstack/react-router'

const className = 'font-medium underline-offset-4 hover:underline'

/** A settings row's name, linking to the record's read-only Overview and History page (M3-21). */
export function RecordLink({
  kind,
  id,
  name,
}: {
  kind: 'agents' | 'teams' | 'categories'
  id: string
  name: string
}) {
  const { workspace = '' } = useParams({ strict: false })
  switch (kind) {
    case 'agents':
      return (
        <Link to="/$workspace/agents/$agentId" params={{ workspace, agentId: id }} className={className}>
          {name}
        </Link>
      )
    case 'teams':
      return (
        <Link to="/$workspace/teams/$teamId" params={{ workspace, teamId: id }} className={className}>
          {name}
        </Link>
      )
    case 'categories':
      return (
        <Link
          to="/$workspace/categories/$categoryId"
          params={{ workspace, categoryId: id }}
          className={className}
        >
          {name}
        </Link>
      )
  }
}

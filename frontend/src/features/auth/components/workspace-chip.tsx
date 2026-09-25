import { Link } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { rememberedWorkspaceName } from '@/lib/auth'
import { initials } from '@/lib/format/initials'

/**
 * Which workspace this page belongs to (M5-03): the name this device remembers from an earlier sign-in,
 * else the slug from the URL, with a way back to choose another. Replaces the read-only "Workspace"
 * field: the workspace is context, not something to fill in.
 */
export function WorkspaceChip({ workspace, changeable = true }: { workspace: string; changeable?: boolean }) {
  const name = rememberedWorkspaceName(workspace)

  return (
    <div className="flex items-center gap-3 rounded-card border border-border bg-surface px-3 py-2 shadow-1">
      <span
        aria-hidden="true"
        className="grid size-8 shrink-0 place-items-center rounded-control bg-primary/15 font-semibold text-foreground text-xs"
      >
        {initials(name ?? workspace)}
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="text-muted-foreground text-xs">{copy.auth.login.workspaceChip}</span>
        <span className="truncate font-medium text-sm" data-testid="workspace-chip-name">
          {name ?? workspace}
          {name && name !== workspace ? (
            <span className="ms-1.5 font-mono font-normal text-muted-foreground text-xs">{workspace}</span>
          ) : null}
        </span>
      </span>
      {changeable ? (
        <Link
          to="/"
          search={{}}
          className="rounded-control px-2 py-1 font-medium text-primary text-sm hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-2"
        >
          {copy.auth.login.change}
          <span className="sr-only"> {copy.auth.login.changeWorkspace}</span>
        </Link>
      ) : null}
    </div>
  )
}

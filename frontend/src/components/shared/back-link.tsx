import { Link } from '@tanstack/react-router'
import { ArrowLeftIcon } from 'lucide-react'

type BackTarget = '/$workspace/contacts' | '/$workspace/organizations' | '/$workspace/tickets'

/** "← Back to …" above a detail page's heading, in the `PageHeader` eyebrow slot. */
export function BackLink({ workspace, to, label }: { workspace: string; to: BackTarget; label: string }) {
  return (
    <Link
      to={to}
      params={{ workspace }}
      className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
    >
      <ArrowLeftIcon aria-hidden="true" className="size-4" />
      {label}
    </Link>
  )
}

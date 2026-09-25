import { ArchiveIcon, CircleCheckIcon, CircleHelpIcon, PauseCircleIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

const STATUS_ICONS = { active: CircleCheckIcon, suspended: PauseCircleIcon, archived: ArchiveIcon } as const
const STATUS_TONES = {
  active: 'text-success',
  suspended: 'text-warning',
  archived: 'text-muted-foreground',
} as const

/** A workspace's status in words with its own icon (not colour alone), as everywhere since M4 (G4). */
export function TenantStatus({ status, className }: { status: string; className?: string }) {
  const labels = copy.platform.statuses as Record<string, string>
  const known = status in STATUS_ICONS ? (status as keyof typeof STATUS_ICONS) : null
  const Icon = known ? STATUS_ICONS[known] : CircleHelpIcon
  return (
    <span className={cn('inline-flex items-center gap-1.5', known ? STATUS_TONES[known] : '', className)}>
      <Icon aria-hidden="true" className="size-4 shrink-0" />
      {labels[status] ?? status}
    </span>
  )
}

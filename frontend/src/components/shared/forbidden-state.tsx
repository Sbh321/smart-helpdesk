import { ShieldXIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { EmptyState } from './empty-state'

/**
 * Rendered instead of data when the session lacks the permission a route needs
 * (docs/06-design-system/components.md §ForbiddenState). It never shows what is behind the gate.
 */
export function ForbiddenState({ description }: { description?: string }) {
  return (
    <EmptyState
      icon={ShieldXIcon}
      title={copy.states.forbidden.title}
      description={description ?? copy.states.forbidden.body}
    />
  )
}

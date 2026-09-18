import { FileQuestionIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { copy } from '@/copy/en'
import { EmptyState } from './empty-state'

/**
 * The `notFoundComponent` of a route (docs/06-design-system/components.md §EmptyState family).
 * The way back is passed in, because only the route knows where "back" is.
 */
export function NotFoundState({ action }: { action?: ReactNode }) {
  return (
    <EmptyState
      icon={FileQuestionIcon}
      title={copy.states.notFound.title}
      description={copy.states.notFound.body}
      action={action}
    />
  )
}

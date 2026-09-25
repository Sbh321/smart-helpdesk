import { LifeBuoyIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

/**
 * The product's mark and name (M5-03, M5-04): one lifebuoy on a primary tile. Used by the
 * pre-authentication frame and the landing site, so the product looks the same before and after sign-in.
 */
export function BrandMark({ className, showName = true }: { className?: string; showName?: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-2 font-semibold text-base tracking-tight', className)}>
      <span className="grid size-7 place-items-center rounded-control bg-primary text-primary-foreground shadow-1">
        <LifeBuoyIcon aria-hidden="true" className="size-4" />
      </span>
      {showName ? copy.app.name : null}
    </span>
  )
}

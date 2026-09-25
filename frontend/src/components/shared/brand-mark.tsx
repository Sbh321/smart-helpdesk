import { LifeBuoyIcon } from 'lucide-react'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

const TILE = {
  sm: 'size-6 rounded-badge [&>svg]:size-4',
  md: 'size-7 rounded-control [&>svg]:size-4',
} as const

/**
 * The product's mark (docs/06-design-system/brand.md): one lifebuoy on a primary tile, the same mark as
 * every favicon (`scripts/brand-icons.mjs`). With its name on the landing site and the sign-in frame; as
 * the tile alone for a workspace without a logo and in the platform console.
 */
export function BrandMark({
  className,
  showName = true,
  size = 'md',
}: {
  className?: string
  showName?: boolean
  size?: keyof typeof TILE
}) {
  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center gap-2 font-semibold text-base tracking-tight',
        className,
      )}
    >
      <span
        className={cn('grid place-items-center bg-primary text-primary-foreground shadow-1', TILE[size])}
        data-slot="brand-tile"
      >
        <LifeBuoyIcon aria-hidden="true" strokeWidth={2.25} />
      </span>
      {showName ? copy.app.name : null}
    </span>
  )
}

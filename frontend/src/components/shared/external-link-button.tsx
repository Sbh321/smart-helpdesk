import { ExternalLinkIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { buttonVariants } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { cn } from '@/lib/utils'

/**
 * A button-styled link to another host (the API reference, the platform documentation) that opens in a
 * new tab (M5-01). `noopener` keeps the new page from reaching back into this one, and the new-tab
 * behaviour is announced, not left for the visitor to discover.
 */
export function ExternalLinkButton({
  href,
  children,
  variant = 'outline',
  size = 'default',
  className,
}: {
  href: string
  children: ReactNode
  variant?: 'outline' | 'ghost' | 'secondary' | 'default'
  size?: 'default' | 'sm'
  className?: string
}) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className={cn(buttonVariants({ variant, size }), className)}
    >
      {children}
      <ExternalLinkIcon aria-hidden="true" />
      <span className="sr-only">{copy.common.opensInNewTab}</span>
    </a>
  )
}

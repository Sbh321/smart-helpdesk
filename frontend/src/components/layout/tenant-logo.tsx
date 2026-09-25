import { useState } from 'react'
import { BrandMark } from '@/components/shared/brand-mark'
import type { SessionTenant } from '@/lib/auth'
import { useTheme } from '@/lib/theme'
import { cn } from '@/lib/utils'

/**
 * Which logo the current theme shows (docs/06-design-system/themes.md §Images and logos): the dark
 * variant in the dark theme; without one, the light logo on a `--surface-elevated` tile.
 */
export function pickTenantLogo(
  branding: Pick<SessionTenant['branding'], 'logo_url' | 'logo_dark_url'> | undefined,
  resolvedTheme: 'light' | 'dark',
): { src: string; tile: boolean } | null {
  if (!branding) {
    return null
  }
  if (resolvedTheme === 'dark') {
    if (branding.logo_dark_url) return { src: branding.logo_dark_url, tile: false }
    return branding.logo_url ? { src: branding.logo_url, tile: true } : null
  }
  return branding.logo_url ? { src: branding.logo_url, tile: false } : null
}

/**
 * The workspace logo, or the product mark (`BrandMark`) when there is none or it cannot be loaded. The image is
 * decorative: the workspace name is always printed next to it.
 */
export function TenantLogo({
  branding,
  className,
}: {
  branding: Pick<SessionTenant['branding'], 'logo_url' | 'logo_dark_url'> | undefined
  className?: string
}) {
  const { resolvedTheme } = useTheme()
  const logo = pickTenantLogo(branding, resolvedTheme)
  const [failed, setFailed] = useState<string | null>(null)

  if (!logo || failed === logo.src) {
    // The workspace's default logo is the product mark, as on every favicon.
    return <BrandMark showName={false} size="sm" className={className} />
  }
  return (
    <img
      src={logo.src}
      alt=""
      data-slot="tenant-logo"
      className={cn(
        'h-6 max-w-24 shrink-0 object-contain',
        logo.tile && 'rounded-md bg-surface-elevated p-1',
        className,
      )}
      onError={() => setFailed(logo.src)}
    />
  )
}

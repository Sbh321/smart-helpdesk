import { createContext, useContext } from 'react'
import type { Density, ResolvedTheme, ThemeChoice } from './theme'

export interface ThemeContextValue {
  /** The stored choice. */
  theme: ThemeChoice
  /** The theme applied now. */
  resolvedTheme: ResolvedTheme
  setTheme: (next: ThemeChoice) => void
  density: Density
  setDensity: (next: Density) => void
  /** The saved primary colour of the workspace (`me.tenant.branding.primary`); null removes the branding. */
  setTenantPrimary: (primary: string | null) => void
  /**
   * An unsaved colour shown instead of the saved one (Settings → Branding live preview): a hex colour,
   * `null` for "no brand colour", or `undefined` to end the preview.
   */
  previewTenantPrimary: (primary: string | null | undefined) => void
}

export const ThemeContext = createContext<ThemeContextValue | null>(null)

/** The only consumer API for theme and density (themes.md). */
export function useTheme(): ThemeContextValue {
  const value = useContext(ThemeContext)
  if (!value) {
    throw new Error('useTheme must be used inside <ThemeProvider>')
  }
  return value
}

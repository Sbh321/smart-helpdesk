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

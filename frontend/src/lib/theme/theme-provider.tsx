import { type ReactNode, useCallback, useEffect, useMemo, useState } from 'react'
import {
  DARK_SCHEME_QUERY,
  DENSITY_STORAGE_KEY,
  type Density,
  parseDensity,
  parseThemeChoice,
  resolveTheme,
  THEME_STORAGE_KEY,
  type ThemeChoice,
} from './theme'
import { ThemeContext } from './theme-context'
import {
  applyTenantBrand,
  applyThemeAttributes,
  readInitialChoice,
  readInitialDensity,
  systemPrefersDark,
  writeStored,
} from './theme-dom'

/**
 * Owns theme and density state and mirrors it onto <html>. The initial state is what the index.html boot
 * script applied, so the first render does not change the page.
 * It also owns the tenant branding: the session hands it the saved primary colour (`setTenantPrimary`),
 * the branding form an unsaved one (`previewTenantPrimary`), and the effective colour becomes
 * `<style id="tenant-brand">` plus `data-tenant` on <html> (themes.md §Tenant branding).
 * MVP-SHORTCUT: preference is local only; V1: sync it with PATCH /v1/me/preferences (themes.md step 5).
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<ThemeChoice>(readInitialChoice)
  const [density, setDensityState] = useState<Density>(readInitialDensity)
  const [prefersDark, setPrefersDark] = useState(systemPrefersDark)
  const [tenantPrimary, setTenantPrimary] = useState<string | null>(null)
  const [previewPrimary, setPreviewPrimary] = useState<string | null | undefined>(undefined)

  const effectivePrimary = previewPrimary === undefined ? tenantPrimary : previewPrimary
  useEffect(() => {
    applyTenantBrand(effectivePrimary)
  }, [effectivePrimary])
  // Leave nothing behind when the provider goes away (tests render many applications in one page).
  useEffect(() => () => applyTenantBrand(null), [])

  useEffect(() => {
    applyThemeAttributes(theme, prefersDark, density)
  }, [theme, prefersDark, density])

  // Follow the operating system only while the choice is `system`.
  useEffect(() => {
    if (theme !== 'system' || typeof window.matchMedia !== 'function') {
      return
    }
    const query = window.matchMedia(DARK_SCHEME_QUERY)
    const onChange = (event: MediaQueryListEvent) => setPrefersDark(event.matches)
    setPrefersDark(query.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [theme])

  // Apply changes made in another tab.
  useEffect(() => {
    const onStorage = (event: StorageEvent) => {
      if (event.key === THEME_STORAGE_KEY) {
        setThemeState(parseThemeChoice(event.newValue))
      } else if (event.key === DENSITY_STORAGE_KEY) {
        setDensityState(parseDensity(event.newValue))
      }
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const setTheme = useCallback((next: ThemeChoice) => {
    writeStored(THEME_STORAGE_KEY, next)
    setThemeState(next)
  }, [])

  const setDensity = useCallback((next: Density) => {
    writeStored(DENSITY_STORAGE_KEY, next)
    setDensityState(next)
  }, [])

  const value = useMemo(
    () => ({
      theme,
      resolvedTheme: resolveTheme(theme, prefersDark),
      setTheme,
      density,
      setDensity,
      setTenantPrimary,
      previewTenantPrimary: setPreviewPrimary,
    }),
    [theme, prefersDark, density, setTheme, setDensity],
  )

  return <ThemeContext value={value}>{children}</ThemeContext>
}

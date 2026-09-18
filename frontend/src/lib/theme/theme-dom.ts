import {
  DARK_SCHEME_QUERY,
  DENSITY_ATTRIBUTE,
  DENSITY_STORAGE_KEY,
  type Density,
  parseDensity,
  parseThemeChoice,
  THEME_CHOICE_ATTRIBUTE,
  THEME_STORAGE_KEY,
  type ThemeChoice,
  themeAttributes,
} from './theme'

/** Storage can throw (private mode, blocked cookies); a failure means "no stored value". */
export function readStored(key: string): string | null {
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

export function writeStored(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // Blocked storage: the choice lasts for this page only (themes.md).
  }
}

export function systemPrefersDark(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia(DARK_SCHEME_QUERY).matches
}

/** Reads what the boot script applied; falls back to storage when the script did not run (tests). */
export function readInitialChoice(root: HTMLElement = document.documentElement): ThemeChoice {
  return parseThemeChoice(root.getAttribute(THEME_CHOICE_ATTRIBUTE) ?? readStored(THEME_STORAGE_KEY))
}

export function readInitialDensity(root: HTMLElement = document.documentElement): Density {
  return parseDensity(root.getAttribute(DENSITY_ATTRIBUTE) ?? readStored(DENSITY_STORAGE_KEY))
}

export function applyThemeAttributes(
  choice: ThemeChoice,
  prefersDark: boolean,
  density: Density,
  root: HTMLElement = document.documentElement,
): void {
  for (const [name, value] of Object.entries(themeAttributes(choice, prefersDark, density))) {
    if (root.getAttribute(name) !== value) {
      root.setAttribute(name, value)
    }
  }
}

/**
 * Theme and density model (docs/06-design-system/themes.md). Pure functions only, so they run in Node tests.
 * The inline boot script in index.html repeats this logic in ES5; keep the two in step.
 */

export type ThemeChoice = 'light' | 'dark' | 'system'
export type ResolvedTheme = 'light' | 'dark'
export type Density = 'comfortable' | 'compact'

export const THEME_CHOICES: readonly ThemeChoice[] = ['light', 'dark', 'system']
export const THEME_STORAGE_KEY = 'sh.theme'
export const DENSITY_STORAGE_KEY = 'sh.density'
export const DARK_SCHEME_QUERY = '(prefers-color-scheme: dark)'

/** `data-theme` is the applied theme, `data-theme-choice` the stored choice, `data-density` the density. */
export const THEME_ATTRIBUTE = 'data-theme'
export const THEME_CHOICE_ATTRIBUTE = 'data-theme-choice'
export const DENSITY_ATTRIBUTE = 'data-density'

/** Unknown, missing or unreadable values fall back to `system`. */
export function parseThemeChoice(value: string | null | undefined): ThemeChoice {
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system'
}

/** Unknown, missing or unreadable values fall back to `comfortable`. */
export function parseDensity(value: string | null | undefined): Density {
  return value === 'compact' ? 'compact' : 'comfortable'
}

/** Resolves the stored choice against the operating-system preference. */
export function resolveTheme(choice: ThemeChoice, prefersDark: boolean): ResolvedTheme {
  if (choice === 'system') {
    return prefersDark ? 'dark' : 'light'
  }
  return choice
}

export interface ThemeAttributes {
  [THEME_ATTRIBUTE]: ResolvedTheme
  [THEME_CHOICE_ATTRIBUTE]: ThemeChoice
  [DENSITY_ATTRIBUTE]: Density
}

/** The attributes `<html>` carries for a given state. */
export function themeAttributes(
  choice: ThemeChoice,
  prefersDark: boolean,
  density: Density,
): ThemeAttributes {
  return {
    [THEME_ATTRIBUTE]: resolveTheme(choice, prefersDark),
    [THEME_CHOICE_ATTRIBUTE]: choice,
    [DENSITY_ATTRIBUTE]: density,
  }
}

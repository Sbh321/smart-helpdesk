import { describe, expect, it } from 'vitest'
import { parseDensity, parseThemeChoice, resolveTheme, type ThemeChoice, themeAttributes } from './theme'

describe('resolveTheme', () => {
  it.each<[ThemeChoice, boolean, 'light' | 'dark']>([
    ['light', false, 'light'],
    ['light', true, 'light'],
    ['dark', false, 'dark'],
    ['dark', true, 'dark'],
    ['system', false, 'light'],
    ['system', true, 'dark'],
  ])('resolves %s with prefersDark=%s to %s', (choice, prefersDark, expected) => {
    expect(resolveTheme(choice, prefersDark)).toBe(expected)
  })
})

describe('parseThemeChoice', () => {
  it.each(['light', 'dark', 'system'] as const)('keeps the valid choice %s', (value) => {
    expect(parseThemeChoice(value)).toBe(value)
  })

  it.each([null, undefined, '', 'Dark', 'auto', 'true'])('falls back to system for %j', (value) => {
    expect(parseThemeChoice(value)).toBe('system')
  })
})

describe('parseDensity', () => {
  it('keeps compact', () => {
    expect(parseDensity('compact')).toBe('compact')
  })

  it.each([null, undefined, '', 'comfortable', 'dense'])('falls back to comfortable for %j', (value) => {
    expect(parseDensity(value)).toBe('comfortable')
  })
})

describe('themeAttributes', () => {
  it('describes the applied theme, the stored choice and the density', () => {
    expect(themeAttributes('system', true, 'compact')).toEqual({
      'data-theme': 'dark',
      'data-theme-choice': 'system',
      'data-density': 'compact',
    })
  })
})

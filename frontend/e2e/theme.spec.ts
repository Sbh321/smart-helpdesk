import { expect, type Page, test } from '@playwright/test'
import { users } from './support/env'
import { signIn } from './support/session'

/**
 * E2E-09, theme persistence (docs/06-design-system/themes.md): the Light / Dark / System choice is
 * applied by the boot script before the first paint, so it survives a reload and a sign-in, and System
 * follows `prefers-color-scheme`. Starts signed out; Chen signs in through the form once.
 */

const html = (page: Page) => page.locator('html')

/** The attributes the index.html boot script set, read before any application code runs. */
async function firstPaintTheme(page: Page) {
  await page.addInitScript(() => {
    document.addEventListener(
      'readystatechange',
      () => {
        const root = document.documentElement
        ;(window as unknown as { __bootTheme: string[] }).__bootTheme = [
          root.getAttribute('data-theme') ?? '',
          root.getAttribute('data-theme-choice') ?? '',
        ]
      },
      { once: true },
    )
  })
}

async function bootTheme(page: Page): Promise<string[]> {
  return page.evaluate(() => (window as unknown as { __bootTheme: string[] }).__bootTheme)
}

test('the chosen theme survives a reload and signing in, and System follows the OS', async ({ page }) => {
  const { workspace } = users.chen
  await page.emulateMedia({ colorScheme: 'light' })
  await firstPaintTheme(page)
  await page.goto(`/${workspace}/login`)
  const themes = page.getByRole('group', { name: 'Theme' })

  await themes.getByRole('button', { name: 'Dark' }).click()
  await expect(html(page)).toHaveAttribute('data-theme', 'dark')
  await expect(themes.getByRole('button', { name: 'Dark' })).toHaveAttribute('aria-pressed', 'true')

  // Reload: dark from the first paint, not after React mounts (no flash of the light theme).
  await page.reload()
  expect(await bootTheme(page)).toEqual(['dark', 'dark'])
  await expect(html(page)).toHaveAttribute('data-theme', 'dark')

  // Sign in: the app shell keeps the choice.
  await signIn(page, 'chen')
  expect(await bootTheme(page)).toEqual(['dark', 'dark'])
  await expect(html(page)).toHaveAttribute('data-theme', 'dark')
  const shellThemes = page.getByRole('banner').getByRole('group', { name: 'Theme' })
  await expect(shellThemes.getByRole('button', { name: 'Dark' })).toHaveAttribute('aria-pressed', 'true')

  // Light, then reload.
  await shellThemes.getByRole('button', { name: 'Light' }).click()
  await expect(html(page)).toHaveAttribute('data-theme', 'light')
  await page.reload()
  expect(await bootTheme(page)).toEqual(['light', 'light'])

  // System follows the operating system, live and after a reload.
  await shellThemes.getByRole('button', { name: 'System' }).click()
  await expect(html(page)).toHaveAttribute('data-theme-choice', 'system')
  await expect(html(page)).toHaveAttribute('data-theme', 'light')
  await page.emulateMedia({ colorScheme: 'dark' })
  await expect(html(page)).toHaveAttribute('data-theme', 'dark')
  await page.reload()
  expect(await bootTheme(page)).toEqual(['dark', 'system'])
  await page.emulateMedia({ colorScheme: 'light' })
  await expect(html(page)).toHaveAttribute('data-theme', 'light')
})

import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { appUrl, landingUrl } from './support/env'

/**
 * The landing site on the apex host (M5-04): readable at every width, keyboard reachable, clean in both
 * themes, and its entry points land where they say.
 */
test.use({ storageState: { cookies: [], origins: [] } })

const WIDTHS = [360, 768, 1024, 1280, 1440]

for (const width of WIDTHS) {
  test(`fits ${width} px without sideways scrolling`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.goto(landingUrl)
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow).toBeLessThanOrEqual(0)
  })
}

for (const scheme of ['light', 'dark'] as const) {
  test(`has no serious or critical axe violations in the ${scheme} theme`, async ({ page }) => {
    await page.emulateMedia({ colorScheme: scheme, reducedMotion: 'reduce' })
    await page.goto(landingUrl)
    await expect(page.locator('html')).toHaveAttribute('data-theme', scheme)
    // Open every answer so their text is checked too.
    await page.locator('#faq details').evaluateAll((items) => {
      for (const item of items) item.setAttribute('open', '')
    })
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
      .analyze()
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    expect(blocking.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
  })
}

test('the skip link comes first and the theme switch is remembered', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' })
  await page.goto(landingUrl)
  await page.keyboard.press('Tab')
  await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused()

  await page.getByRole('button', { name: 'Switch between light and dark' }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

test('the phone menu opens and its links work', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 780 })
  await page.goto(landingUrl)
  await page.locator('header summary').click()
  await page.locator('header details').getByRole('link', { name: 'FAQ' }).click()
  await expect(page).toHaveURL(/#faq$/)
})

test('open the app and find your workspace land on the sign-in host', async ({ page }) => {
  await page.goto(landingUrl)
  await page.getByRole('link', { name: 'Find your workspace' }).first().click()
  await expect(page).toHaveURL(`${appUrl}/?find=true`)
  await expect(page.getByRole('heading', { level: 1, name: 'Find your workspace' })).toBeVisible()

  await page.goto(landingUrl)
  await page.getByRole('link', { name: 'Open the app' }).first().click()
  await expect(page).toHaveURL(`${appUrl}/`)
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in to your workspace' })).toBeVisible()
})

test('serves metadata, a licence notice and cacheable assets', async ({ page, request }) => {
  await page.goto(landingUrl)
  await expect(page).toHaveTitle(/Smart Helpdesk/)
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /multi-tenant helpdesk/)
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', '/images/og-image.png')

  const licences = await request.get(`${landingUrl}/third-party-licences.txt`)
  expect(await licences.text()).toContain('Tailark')

  const css = await page.locator('link[rel="stylesheet"]').getAttribute('href')
  const asset = await request.get(`${landingUrl}${css}`)
  expect(asset.headers()['cache-control']).toContain('immutable')
})

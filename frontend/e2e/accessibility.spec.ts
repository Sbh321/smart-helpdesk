import AxeBuilder from '@axe-core/playwright'
import { type Browser, expect, type Page, test } from '@playwright/test'
import { stateFor, type UserKey, users } from './support/env'
import { first, sessionApi } from './support/session'

/**
 * axe-core scans of the main screens in the light and the dark theme (docs/06-design-system/
 * accessibility.md §Testing): WCAG 2.2 AA rule sets, and a `serious` or `critical` finding fails the
 * test. Lesser findings are attached to the report. Meera (Owner) sees every settings page.
 */

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
const workspace = users.meera.workspace

type Screen = {
  name: string
  path: string | ((browser: Browser) => Promise<string>)
  user?: UserKey
  /** Brings the screen into the state to scan, e.g. an open dialog. */
  open?: (page: Page) => Promise<void>
}

let ticketPath: string | undefined
async function ticketDetail(browser: Browser): Promise<string> {
  if (ticketPath) return ticketPath
  const context = await browser.newContext({ storageState: stateFor('meera'), ignoreHTTPSErrors: true })
  try {
    // An anchor ticket of the demo dataset with comments, SLA timers and a priority explanation.
    const found = await sessionApi(context).json<{ data: { id: string; number: number }[] }>(
      '/tickets?search=1031&per_page=5',
    )
    const ticket =
      found.data.find((row) => row.number === 1031) ??
      first(
        (await sessionApi(context).json<{ data: { id: string }[] }>('/tickets?per_page=1')).data,
        'a ticket',
      )
    ticketPath = `/${workspace}/tickets/${ticket.id}`
    return ticketPath
  } finally {
    await context.close()
  }
}

const screens: Screen[] = [
  { name: 'login', path: `/${workspace}/login`, user: undefined },
  { name: 'dashboard', path: `/${workspace}` },
  { name: 'tickets list', path: `/${workspace}/tickets` },
  {
    name: 'new ticket dialog',
    path: `/${workspace}/tickets`,
    open: async (page) => {
      await page.getByRole('button', { name: 'New ticket' }).first().click()
      await expect(page.getByRole('dialog', { name: 'New ticket' })).toBeVisible()
    },
  },
  { name: 'ticket detail', path: ticketDetail },
  {
    name: 'priority explanation',
    path: ticketDetail,
    open: async (page) => {
      await page.getByRole('button', { name: 'Why this priority?' }).click()
      await expect(page.getByText('basic_weighted_priority', { exact: false }).first()).toBeVisible()
    },
  },
  { name: 'reports catalogue', path: `/${workspace}/reports` },
  { name: 'settings general', path: `/${workspace}/settings/general` },
  { name: 'settings automation', path: `/${workspace}/settings/automation` },
  { name: 'settings users', path: `/${workspace}/settings/users` },
  {
    name: 'invite user dialog',
    path: `/${workspace}/settings/users`,
    open: async (page) => {
      await page.getByRole('button', { name: 'Invite user' }).click()
      await expect(page.getByRole('dialog', { name: 'Invite a user' })).toBeVisible()
    },
  },
  { name: 'settings webhooks', path: `/${workspace}/settings/webhooks` },
  {
    name: 'add webhook dialog',
    path: `/${workspace}/settings/webhooks`,
    open: async (page) => {
      await page.getByRole('button', { name: 'Add webhook' }).click()
      await expect(
        page.getByRole('dialog', { name: 'Add a webhook' }).getByRole('checkbox').first(),
      ).toBeVisible()
    },
  },
  { name: 'settings API clients', path: `/${workspace}/settings/api-clients` },
  { name: 'settings audit log', path: `/${workspace}/settings/audit` },
  { name: 'settings email', path: `/${workspace}/settings/email` },
]

/** Data loaded, no skeletons, animations finished: axe then measures the colours a user sees. */
async function settle(page: Page) {
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[data-slot="skeleton"]')).toHaveCount(0)
  await page.evaluate(() =>
    Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined))),
  )
}

for (const theme of ['light', 'dark'] as const) {
  test.describe(`${theme} theme`, () => {
    for (const screen of screens) {
      test.describe(() => {
        const user = 'user' in screen ? screen.user : 'meera'
        test.use({
          storageState: user ? stateFor(user) : { cookies: [], origins: [] },
          colorScheme: theme,
        })

        test(`${screen.name} has no serious or critical axe violations`, async ({ page, browser }, info) => {
          await page.addInitScript((choice) => {
            try {
              window.localStorage.setItem('sh.theme', choice)
            } catch {
              // storage blocked: the colour scheme emulation still selects the theme
            }
          }, theme)
          const path = typeof screen.path === 'string' ? screen.path : await screen.path(browser)
          await page.goto(path)
          await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
          await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
          await settle(page)
          if (screen.open) {
            await screen.open(page)
            await settle(page)
          }

          const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze()
          const summary = results.violations.map((violation) => ({
            id: violation.id,
            impact: violation.impact,
            help: violation.help,
            nodes: violation.nodes.map((node) => `${node.target.join(' ')} — ${node.failureSummary ?? ''}`),
          }))
          await info.attach('axe-violations.json', {
            body: JSON.stringify(summary, null, 2),
            contentType: 'application/json',
          })
          const blocking = summary.filter(
            (violation) => violation.impact === 'serious' || violation.impact === 'critical',
          )
          expect(blocking, JSON.stringify(blocking, null, 2)).toEqual([])
          if (process.env.E2E_AXE_REPORT) {
            console.log(
              `axe ${theme} ${screen.name}: ${results.passes.length} rules passed, ${summary.length} violations${
                summary.length
                  ? ` (${summary.map((violation) => `${violation.id}/${violation.impact}`).join(', ')})`
                  : ''
              }`,
            )
          }
        })
      })
    }
  })
}

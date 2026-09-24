import axe from 'axe-core'
import { delay, HttpResponse, http } from 'msw'
import { afterEach, expect, test } from 'vitest'
import { page } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * Roadmap M4-13: every asynchronous view has five distinct states, and the app is usable at 1024 px.
 * Two representative lists (the ticket queue and a settings directory) go through loading, empty,
 * no-results, error and forbidden; the ticket page is checked at 1024 px for its drawer, rail and width.
 */
const worker = setupMswWorker()
const MANAGER = ['tickets.view', 'tickets.update', 'agents.view', 'agents.manage', 'contacts.view']

function signIn(permissions = MANAGER) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

afterEach(async () => {
  await page.viewport(1280, 800)
})

test('the skills list: a skeleton while loading, then empty, no results, error and forbidden are each worded', async () => {
  signIn()
  worker.use(
    http.get(apiUrl('/skills'), async () => {
      await delay(400)
      return HttpResponse.json({
        data: [],
        links: {},
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      })
    }),
  )
  const app = await renderApp('/acme/settings/skills')
  // Loading: the shape of the list, with its words for screen readers.
  await expect
    .element(app.screen.getByRole('status').filter({ hasText: copy.settings.loading }))
    .toBeInTheDocument()
  // Empty: nothing yet.
  await expect.element(app.screen.getByText(copy.settings.empty)).toBeVisible()
  await app.screen.unmount()
  worker.resetHandlers()

  // No results: a search that matches nothing says so, with a way out.
  signIn()
  const search = await renderApp('/acme/settings/skills?search=zzzz')
  await expect
    .element(search.screen.getByText(fill(copy.settings.noMatches, { search: 'zzzz' })))
    .toBeVisible()
  await search.screen.getByRole('button', { name: copy.settings.clearSearch }).click()
  await expect.element(search.screen.getByRole('cell', { name: db.skills[0]?.name ?? '' })).toBeVisible()
  await search.screen.unmount()

  // Error: the failure in words and a retry.
  signIn()
  worker.use(http.get(apiUrl('/skills'), () => problem(500, 'server_error', { title: 'Server error' })))
  const failed = await renderApp('/acme/settings/skills')
  await expect.element(failed.screen.getByRole('button', { name: copy.states.error.retry })).toBeVisible()
  await failed.screen.unmount()

  // Forbidden: without the permission the section is not offered at all.
  signIn(['tickets.view'])
  const forbidden = await renderApp('/acme/settings/skills')
  await expect
    .element(forbidden.screen.getByRole('navigation', { name: copy.settings.sections }))
    .toBeVisible()
  expect(forbidden.screen.getByRole('link', { name: copy.settings.skills }).query()).toBeNull()
})

test('the ticket queue: "no tickets yet" is not "no tickets match these filters", and errors offer a retry', async () => {
  signIn()
  db.tickets = []
  const empty = await renderApp('/acme/tickets')
  await expect.element(empty.screen.getByText(copy.tickets.list.emptyTitle)).toBeVisible()
  await empty.screen.unmount()

  signIn()
  const filtered = await renderApp('/acme/tickets?status=closed&priority=P1')
  await expect.element(filtered.screen.getByText(copy.tickets.list.noMatchesTitle)).toBeVisible()
  expect(filtered.screen.getByText(copy.tickets.list.emptyTitle).query()).toBeNull()
  await filtered.screen.unmount()

  signIn()
  worker.use(http.get(apiUrl('/tickets'), () => problem(500, 'server_error', { title: 'Server error' })))
  const failed = await renderApp('/acme/tickets')
  await expect.element(failed.screen.getByRole('button', { name: copy.states.error.retry })).toBeVisible()
})

test('at 1024 px the navigation is an icon rail, the ticket context is a drawer, and nothing scrolls sideways', async () => {
  await page.viewport(1024, 800)
  signIn([...MANAGER, 'tickets.assign'])
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  const { screen } = await renderApp(`/acme/tickets/${ticket.id}`)
  await expect.element(screen.getByRole('heading', { level: 1 })).toBeVisible()

  // The rail: expanding is offered, and the labels are the links' accessible names.
  await expect.element(screen.getByRole('button', { name: copy.nav.expand })).toBeVisible()
  await expect.element(screen.getByRole('link', { name: copy.nav.tickets })).toBeVisible()

  // The context is behind a button, not a squeezed column.
  expect(screen.getByRole('complementary', { name: copy.tickets.detail.context }).query()).toBeNull()
  await screen.getByRole('button', { name: copy.tickets.detail.context }).click()
  const drawer = screen.getByRole('dialog', { name: copy.tickets.detail.context })
  await expect.element(drawer).toBeVisible()
  await expect.element(drawer.getByText(copy.tickets.detail.requester, { exact: true })).toBeVisible()
  await drawer.getByRole('button', { name: copy.tickets.detail.closeContext }).click()
  await expect.element(drawer).not.toBeInTheDocument()

  expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(document.documentElement.clientWidth)

  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
  const results = await axe.run(document.body, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
  })
  expect(
    results.violations
      .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
      .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`),
  ).toEqual([])
})

test('at 1280 px the navigation is open and the context is a column beside the conversation', async () => {
  signIn()
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  const { screen } = await renderApp(`/acme/tickets/${ticket.id}`)
  await expect.element(screen.getByRole('complementary', { name: copy.tickets.detail.context })).toBeVisible()
  await expect.element(screen.getByRole('button', { name: copy.nav.collapse })).toBeVisible()
  expect(screen.getByRole('button', { name: copy.tickets.detail.context }).query()).toBeNull()
})

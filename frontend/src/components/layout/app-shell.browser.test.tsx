import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

async function openShell(path = '/acme') {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture() })))
  const app = await renderApp(path)
  await expect
    .element(app.screen.getByRole('heading', { level: 1, name: copy.dashboard.title }))
    .toBeVisible()
  return app
}

test('the skip link is the first thing the keyboard reaches and points at the main landmark', async () => {
  const { screen } = await openShell()

  await userEvent.tab()

  const skip = screen.getByRole('link', { name: copy.app.skipToContent })
  await expect.element(skip).toHaveFocus()
  expect(skip.element().getAttribute('href')).toBe('#main')
  expect(document.querySelector('main')?.id).toBe('main')
})

test('the account menu opens and signs the user out', async () => {
  const { screen, currentPath } = await openShell()
  worker.use(
    http.post(apiUrl('/auth/logout'), () => new HttpResponse(null, { status: 204 })),
    http.get(apiUrl('/me'), () => problem(401, 'unauthenticated')),
  )

  await screen.getByRole('button', { name: copy.nav.account }).click()
  const menu = screen.getByRole('menu')
  await expect.element(menu).toBeVisible()
  await expect.element(menu.getByText('priya@acme.test')).toBeVisible()

  await menu.getByRole('menuitem', { name: copy.auth.signOut }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.auth.login.heading })).toBeVisible()
  expect(currentPath()).toBe('/acme/login')
})

test('the command palette opens with Ctrl+K and navigates', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'] }) }),
    ),
  )
  const { screen, currentPath } = await renderApp('/acme')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()

  await userEvent.keyboard('{Control>}k{/Control}')
  const dialog = screen.getByRole('dialog')
  await expect.element(dialog).toBeVisible()

  await dialog.getByRole('link', { name: copy.nav.tickets }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.tickets.title })).toBeVisible()
  expect(currentPath()).toBe('/acme/tickets')
})

test('the breadcrumb trail names the page and links back to the dashboard', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.tickets.title })).toBeVisible()

  const trail = screen.getByRole('navigation', { name: copy.shell.breadcrumbs })
  await expect.element(trail.getByRole('link', { name: copy.nav.dashboard })).toBeVisible()
  await expect.element(trail.getByText(copy.tickets.title)).toBeVisible()
})

test('the account menu carries theme and density, and applies the choice to the document', async () => {
  const { screen } = await openShell()
  const root = document.documentElement

  await screen.getByRole('button', { name: copy.nav.account }).click()
  const menu = screen.getByRole('menu')
  await expect.element(menu.getByText(copy.theme.label)).toBeVisible()

  await menu.getByRole('menuitemradio', { name: copy.theme.dark }).click()
  await expect.poll(() => root.getAttribute('data-theme')).toBe('dark')

  // Density had no control before M4-02, although the tokens supported it.
  await menu.getByRole('menuitemradio', { name: copy.density.compact }).click()
  await expect.poll(() => root.getAttribute('data-density')).toBe('compact')

  await menu.getByRole('menuitemradio', { name: copy.theme.light }).click()
  await expect.poll(() => root.getAttribute('data-theme')).toBe('light')
})

test('the navigation is grouped and collapses to an icon rail that keeps its labels', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({ permissions: ['tickets.view', 'contacts.view', 'reports.view'] }),
      }),
    ),
  )
  const app = await renderApp('/acme')
  const { screen } = app
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()

  const nav = screen.getByRole('navigation', { name: copy.nav.primary })
  await expect.element(nav.getByRole('heading', { level: 2, name: copy.nav.groups.records })).toBeVisible()
  await expect.element(nav.getByRole('link', { name: copy.nav.tickets })).toBeVisible()

  await nav.getByRole('button', { name: copy.nav.collapse }).click()

  // Collapsed: the visible text goes, the accessible name stays, and the choice is remembered.
  await expect.element(nav.getByRole('link', { name: copy.nav.tickets })).toBeVisible()
  await expect
    .element(nav.getByRole('heading', { level: 2, name: copy.nav.groups.records }))
    .not.toBeInTheDocument()
  expect(window.localStorage.getItem('sh.nav-collapsed')).toBe('true')

  await nav.getByRole('button', { name: copy.nav.expand }).click()
  await expect.element(nav.getByRole('heading', { level: 2, name: copy.nav.groups.records })).toBeVisible()
  expect(window.localStorage.getItem('sh.nav-collapsed')).toBe('false')
})

test('the API reference link opens the docs host in a new tab, only for integration managers (M5-01)', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'] }) }),
    ),
  )
  const agent = await renderApp('/acme')
  await expect
    .element(agent.screen.getByRole('heading', { level: 1, name: copy.dashboard.title }))
    .toBeVisible()
  expect(agent.screen.getByRole('link', { name: new RegExp(copy.nav.apiReference) }).query()).toBeNull()
  agent.screen.unmount()

  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['integrations.manage'] }) }),
    ),
  )
  const developer = await renderApp('/acme')
  const link = developer.screen
    .getByRole('navigation', { name: copy.nav.primary })
    .getByRole('link', { name: new RegExp(copy.nav.apiReference) })
  await expect.element(link).toBeVisible()
  expect(link.element().getAttribute('href')).toBe('https://docs.shp.test')
  expect(link.element().getAttribute('target')).toBe('_blank')
  expect(link.element().getAttribute('rel')).toContain('noopener')
})

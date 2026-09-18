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

test('the notification bell opens and says where the real list is coming from', async () => {
  const { screen } = await openShell()

  await screen.getByRole('button', { name: copy.shell.notifications.label }).click()

  await expect.element(screen.getByText(copy.shell.notifications.none)).toBeVisible()
  await expect.element(screen.getByText(copy.shell.notifications.placeholder)).toBeVisible()
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

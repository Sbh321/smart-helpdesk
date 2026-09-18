import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

function signedInAs(slug: string, permissions: string[] = []) {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          tenant: { ...sessionFixture().tenant, slug, name: slug === 'acme' ? 'Acme' : 'Globex' },
          permissions,
        }),
      }),
    ),
  )
}

test('sends an anonymous visitor to sign in and remembers where they were going', async () => {
  const { screen, currentPath, currentLocation } = await renderApp('/acme/tickets')

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.auth.login.heading })).toBeVisible()
  expect(currentPath()).toBe('/acme/login')
  expect(currentLocation().search).toEqual({ redirect: '/acme/tickets' })
})

test('a signed-in visitor sees the shell, not the sign-in page', async () => {
  signedInAs('acme')
  const { screen, currentPath } = await renderApp('/acme/login')

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()
  expect(currentPath()).toBe('/acme')
})

test('corrects a URL that names another workspace than the session (ADR-0021)', async () => {
  signedInAs('acme')
  const { screen, currentPath } = await renderApp('/globex/tickets')

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.tickets.title })).toBeVisible()
  expect(currentPath()).toBe('/acme/tickets')
})

test('shows only the navigation the permissions allow', async () => {
  signedInAs('acme')
  const { screen } = await renderApp('/acme')

  const nav = screen.getByRole('navigation', { name: copy.nav.primary })
  await expect.element(nav.getByRole('link', { name: copy.nav.dashboard })).toBeVisible()
  // `/v1/me` returns no permissions until roadmap M1-09, so the gated items stay hidden.
  await expect.element(nav.getByRole('link', { name: copy.nav.tickets })).not.toBeInTheDocument()
  await expect.element(nav.getByRole('link', { name: copy.nav.settings })).not.toBeInTheDocument()
})

test('adds a navigation item as soon as its permission is granted', async () => {
  signedInAs('acme', ['tickets.view'])
  const { screen } = await renderApp('/acme')

  const nav = screen.getByRole('navigation', { name: copy.nav.primary })
  await expect.element(nav.getByRole('link', { name: copy.nav.tickets })).toBeVisible()
  await expect.element(nav.getByRole('link', { name: copy.nav.contacts })).not.toBeInTheDocument()
})

test('renders the forbidden state instead of the data when the permission is missing', async () => {
  signedInAs('acme')
  const { screen } = await renderApp('/acme/tickets')

  await expect.element(screen.getByText(copy.states.forbidden.title)).toBeVisible()
  expect(screen.getByRole('table').query()).toBeNull()
})

test('shows the workspace, the signed-in user and a skip link in the shell', async () => {
  signedInAs('acme')
  const { screen } = await renderApp('/acme')

  await expect.element(screen.getByRole('link', { name: copy.app.skipToContent })).toBeInTheDocument()
  await expect
    .element(screen.getByRole('navigation', { name: copy.nav.primary }).getByText('Acme'))
    .toBeVisible()
  await expect.element(screen.getByRole('main')).toBeVisible()
  await expect.element(screen.getByRole('banner')).toBeVisible()
})

test('a workspace segment that cannot be a slug is not found', async () => {
  const { screen } = await renderApp('/Not_A_Slug')

  await expect.element(screen.getByText(copy.states.notFound.title)).toBeVisible()
})

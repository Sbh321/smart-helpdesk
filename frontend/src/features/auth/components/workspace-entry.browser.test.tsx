import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { afterEach, expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * The workspace step before sign-in (M5-03): workspaces used on this device first, a field that takes a
 * pasted address, and "Find it by email" (M5-02).
 */
const worker = setupMswWorker()
const KEY = 'sh.recent-workspaces'

afterEach(() => window.localStorage.removeItem(KEY))

async function openEntry(path = '/') {
  worker.use(http.get(apiUrl('/me'), () => problem(401, 'unauthenticated')))
  const app = await renderApp(path)
  return app
}

async function blocking(): Promise<string[]> {
  const results = await axe.run(document.body, { resultTypes: ['violations'] })
  return results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)
}

test('takes a pasted address, previews it, and opens that workspace’s sign-in', async () => {
  const { screen, currentPath } = await openEntry()
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.workspaceEntry.heading }))
    .toBeVisible()

  await screen
    .getByRole('textbox', { name: copy.workspaceEntry.label })
    .fill('https://app.shp.test/globex/login')
  await expect
    .element(screen.getByText(fill(copy.workspaceEntry.preview, { address: 'app.shp.test/globex' })))
    .toBeVisible()
  await screen.getByRole('button', { name: copy.workspaceEntry.submit }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.auth.login.heading })).toBeVisible()
  expect(currentPath()).toBe('/globex/login')
  expect(await blocking()).toEqual([])
})

test('explains an entry that cannot be a workspace', async () => {
  const { screen, currentPath } = await openEntry()
  await screen.getByRole('textbox', { name: copy.workspaceEntry.label }).fill('acme_corp!')
  await screen.getByRole('button', { name: copy.workspaceEntry.submit }).click()

  await expect.element(screen.getByText(copy.workspaceEntry.invalid)).toBeVisible()
  expect(currentPath()).toBe('/')
})

test('offers the workspaces used on this device first, and forgets one on request', async () => {
  window.localStorage.setItem(
    KEY,
    JSON.stringify([
      { slug: 'acme', name: 'Acme Support', lastUsedAt: '2026-09-25T08:00:00Z' },
      { slug: 'globex', name: 'Globex', lastUsedAt: '2026-09-24T08:00:00Z' },
    ]),
  )
  const { screen, currentPath } = await openEntry()

  const recent = screen.getByRole('region', { name: copy.workspaceEntry.recentHeading })
  await expect.element(recent).toBeVisible()
  await expect
    .element(screen.getByRole('textbox', { name: copy.workspaceEntry.otherWorkspace }))
    .toBeVisible()

  await recent.getByRole('button', { name: fill(copy.workspaceEntry.forget, { name: 'Globex' }) }).click()
  expect(
    recent.getByRole('link', { name: fill(copy.workspaceEntry.recentOpen, { name: 'Globex' }) }).query(),
  ).toBeNull()
  expect(JSON.parse(window.localStorage.getItem(KEY) ?? '[]')).toHaveLength(1)
  expect(await blocking()).toEqual([])

  await recent
    .getByRole('link', { name: fill(copy.workspaceEntry.recentOpen, { name: 'Acme Support' }) })
    .click()
  await expect.poll(() => currentPath()).toBe('/acme/login')
  // The sign-in page names the workspace as this device remembers it.
  await expect.element(screen.getByTestId('workspace-chip-name')).toHaveTextContent('Acme Supportacme')
})

test('emails a person their workspaces, and says so without revealing whether they exist', async () => {
  let body: unknown = null
  worker.use(
    http.post(apiUrl('/auth/workspace-reminder'), async ({ request }) => {
      body = await request.json()
      return HttpResponse.json({ data: { status: 'sent' } }, { status: 202 })
    }),
  )
  const { screen, currentPath } = await openEntry()
  await screen.getByRole('link', { name: copy.workspaceEntry.findLink }).click()
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.workspaceFinder.heading }))
    .toBeVisible()

  await screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('priya@acme.test')
  await screen.getByRole('button', { name: copy.workspaceFinder.submit }).click()

  await expect
    .element(screen.getByRole('heading', { level: 2, name: copy.workspaceFinder.sentHeading }))
    .toBeVisible()
  await expect
    .element(screen.getByText(fill(copy.workspaceFinder.sentBody, { email: 'priya@acme.test' })))
    .toBeVisible()
  expect(body).toEqual({ email: 'priya@acme.test' })
  expect(await blocking()).toEqual([])

  await screen.getByRole('link', { name: copy.workspaceFinder.back }).click()
  await expect.poll(() => currentPath()).toBe('/')
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.workspaceEntry.heading }))
    .toBeVisible()
})

test('the finder checks the address before asking the API', async () => {
  let calls = 0
  worker.use(
    http.post(apiUrl('/auth/workspace-reminder'), () => {
      calls += 1
      return HttpResponse.json({ data: { status: 'sent' } }, { status: 202 })
    }),
  )
  const { screen } = await openEntry('/?find=true')
  await screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('not-an-address')
  await screen.getByRole('button', { name: copy.workspaceFinder.submit }).click()

  await expect.element(screen.getByText(copy.auth.validation.emailInvalid)).toBeVisible()
  expect(calls).toBe(0)
})

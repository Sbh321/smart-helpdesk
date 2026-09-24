import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { problem } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * The platform console on the admin host (feat/admin-auth, aligned with the M4 redesign): sign-in with
 * the app-wide form timing, the tenant list in words, and the account menu of the workspace shell.
 * `/platform-api/*` is same-origin, so the mock matches any origin.
 */
const worker = setupMswWorker()
const ADMIN = { id: 'admin-1', name: 'Platform Admin', email: 'admin@platform.test', last_login_at: null }
const TENANTS = [
  {
    id: 't1',
    slug: 'acme',
    name: 'Acme Support',
    status: 'active',
    plan: 'standard',
    placement: 'shared',
    owner_email: 'meera@acme.test',
    timezone: 'Asia/Kathmandu',
    suspended_at: null,
    created_at: '2026-09-01T04:15:00Z',
  },
  {
    id: 't2',
    slug: 'globex',
    name: 'Globex',
    status: 'suspended',
    plan: 'premium',
    placement: 'shared',
    owner_email: null,
    timezone: 'UTC',
    suspended_at: '2026-09-20T00:00:00Z',
    created_at: null,
  },
]

function platformApi(state: { signedIn: boolean }) {
  worker.use(
    http.get('*/platform-api/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
    http.get('*/platform-api/me', () =>
      state.signedIn ? HttpResponse.json({ data: ADMIN }) : problem(401, 'unauthenticated'),
    ),
    http.post('*/platform-api/auth/login', async ({ request }) => {
      const body = (await request.json()) as { email: string; password: string }
      if (body.password !== 'password') return problem(401, 'invalid_credentials')
      state.signedIn = true
      return new HttpResponse(null, { status: 204 })
    }),
    http.post('*/platform-api/auth/logout', () => {
      state.signedIn = false
      return new HttpResponse(null, { status: 204 })
    }),
    http.get('*/platform-api/tenants', () =>
      state.signedIn ? HttpResponse.json({ data: TENANTS }) : problem(401, 'unauthenticated'),
    ),
  )
}

async function blocking(): Promise<string[]> {
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
  const results = await axe.run(document.body, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
  })
  return results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
}

test('a signed-out visitor is sent to the platform sign-in, and a wrong password is said in words', async () => {
  platformApi({ signedIn: false })
  const app = await renderApp('/platform/tenants')
  await expect.poll(() => app.currentPath()).toBe('/platform/login')
  const { screen } = app
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.platform.login.heading }))
    .toBeVisible()

  // Validation on submit first, then as each field changes: the error clears once fixed (M4-13).
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  const email = screen.getByRole('textbox', { name: copy.auth.emailLabel })
  await expect.element(email).toHaveAttribute('aria-invalid', 'true')
  await email.fill('admin@platform.test')
  await expect.element(email).not.toHaveAttribute('aria-invalid', 'true')

  await screen.getByLabelText(copy.auth.passwordLabel).fill('wrong')
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  await expect.element(screen.getByText(copy.platform.login.invalidCredentials)).toBeVisible()
  expect(await blocking()).toEqual([])
})

test('signing in opens the tenant list in words, and the account menu signs out', async () => {
  const state = { signedIn: false }
  platformApi(state)
  const app = await renderApp('/platform/login')
  const { screen } = app
  await screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('admin@platform.test')
  await screen.getByLabelText(copy.auth.passwordLabel).fill('password')
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform/tenants')

  const table = screen.getByRole('table', { name: copy.platform.tableLabel })
  await expect
    .element(table.getByRole('row', { name: /Acme Support/ }))
    .toMatchTextContent(/Active\s*Standard\s*1 Sep 2026/)
  await expect
    .element(table.getByRole('row', { name: /Globex/ }))
    .toMatchTextContent(/Suspended\s*Premium\s*—/)
  // No stored value reaches the page as it is stored.
  expect(table.element().textContent).not.toMatch(/\bactive\b|\bsuspended\b|\bpremium\b/)
  // Theme and density live in the account menu, not in the bar (M4-02).
  expect(screen.getByRole('group', { name: copy.theme.label }).query()).toBeNull()
  expect(await blocking()).toEqual([])

  await screen.getByRole('button', { name: copy.nav.account }).click()
  await expect.element(screen.getByText(`Signed in as ${ADMIN.name}`)).toBeVisible()
  await expect.element(screen.getByRole('menuitemradio', { name: copy.theme.dark })).toBeVisible()
  await screen.getByRole('menuitem', { name: copy.platform.signOut }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform/login')
  expect(state.signedIn).toBe(false)
})

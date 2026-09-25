import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

/**
 * `GET /v1/me` answers 401 until the sign-in request succeeds, which is what the API does: the session
 * only exists after the login call sets its cookie.
 */
function mockSuccessfulSignIn(permissions: string[] = []): void {
  let signedIn = false
  worker.use(
    http.post(apiUrl('/auth/login'), () => {
      signedIn = true
      return HttpResponse.json({ data: null })
    }),
    http.get(apiUrl('/me'), () =>
      signedIn
        ? HttpResponse.json({ data: sessionFixture({ permissions }) })
        : problem(401, 'unauthenticated'),
    ),
  )
}

async function openLogin(path = '/acme/login') {
  const app = await renderApp(path)
  await expect
    .element(app.screen.getByRole('heading', { level: 1, name: copy.auth.login.heading }))
    .toBeVisible()
  return app
}

async function fillCredentials(screen: Awaited<ReturnType<typeof openLogin>>['screen']) {
  await screen.getByLabelText(copy.auth.emailLabel).fill('priya@acme.test')
  await screen.getByLabelText(copy.auth.passwordLabel, { exact: true }).fill('correct horse battery')
}

test('shows the workspace from the URL as context with a way to change it, never as a field', async () => {
  const { screen, currentPath } = await openLogin()

  await expect.element(screen.getByTestId('workspace-chip-name')).toHaveTextContent('acme')
  expect(screen.getByRole('textbox', { name: copy.auth.workspaceLabel }).query()).toBeNull()

  await screen.getByRole('link', { name: new RegExp(copy.auth.login.changeWorkspace) }).click()
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.workspaceEntry.heading }))
    .toBeVisible()
  expect(currentPath()).toBe('/')
})

test('the password can be shown and hidden, and the reset link follows it in tab order (M5-03)', async () => {
  const { screen } = await openLogin()
  const password = screen.getByLabelText(copy.auth.passwordLabel, { exact: true })
  await password.fill('secret words')
  await expect.element(password).toHaveAttribute('type', 'password')

  const toggle = screen.getByRole('button', { name: copy.auth.password.show })
  await toggle.click()
  await expect.element(password).toHaveAttribute('type', 'text')
  await expect
    .element(screen.getByRole('button', { name: copy.auth.password.hide }))
    .toHaveAttribute('aria-pressed', 'true')

  const order = [...document.querySelectorAll('input, button, a[href]')].map(
    (element) => element.getAttribute('aria-label') ?? element.getAttribute('id') ?? element.textContent,
  )
  expect(order.indexOf('login-password')).toBeLessThan(order.indexOf(copy.auth.login.forgot))
})

test('remembers the workspace on this device after signing in (M5-03)', async () => {
  window.localStorage.removeItem('sh.recent-workspaces')
  mockSuccessfulSignIn(['tickets.view'])
  const { screen } = await openLogin()
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()
  const stored = JSON.parse(window.localStorage.getItem('sh.recent-workspaces') ?? '[]') as { slug: string }[]
  expect(stored.map((item) => item.slug)).toEqual(['acme'])
  window.localStorage.removeItem('sh.recent-workspaces')
})

test('maps a 422 to the fields it names, tied to the inputs with aria-describedby', async () => {
  worker.use(
    http.post(apiUrl('/auth/login'), () =>
      problem(422, 'validation_failed', {
        errors: {
          email: ['The email field must be a valid address.'],
          password: ['The password field is required.'],
        },
      }),
    ),
  )
  const { screen } = await openLogin()
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  const emailError = screen.getByText('The email field must be a valid address.')
  await expect.element(emailError).toBeVisible()
  await expect.element(screen.getByText('The password field is required.')).toBeVisible()

  const email = screen.getByLabelText(copy.auth.emailLabel)
  await expect.element(email).toHaveAttribute('aria-invalid', 'true')
  await expect.element(email).toHaveAttribute('aria-describedby', 'login-email-error')
  expect(emailError.element().id).toBe('login-email-error')
  // A 422 belongs on the fields, not in the banner.
  await expect.element(screen.getByText(copy.auth.login.failed)).not.toBeInTheDocument()
})

test('shows one banner for a 401, without saying which part was wrong', async () => {
  worker.use(http.post(apiUrl('/auth/login'), () => problem(401, 'invalid_credentials')))
  const { screen } = await openLogin()
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByText(copy.auth.login.failed)).toBeVisible()
  await expect.element(screen.getByText(copy.auth.errors.invalidCredentials)).toBeVisible()
})

test('explains a locked account with the wait from the problem details', async () => {
  worker.use(
    http.post(apiUrl('/auth/login'), () =>
      problem(403, 'account_locked', { meta: { retry_after_minutes: 15 } }),
    ),
  )
  const { screen } = await openLogin()
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByText(fill(copy.auth.errors.accountLocked, { minutes: 15 }))).toBeVisible()
})

test('validates the fields in the browser before asking the API', async () => {
  let calls = 0
  worker.use(
    http.post(apiUrl('/auth/login'), () => {
      calls += 1
      return problem(401, 'invalid_credentials')
    }),
  )
  const { screen } = await openLogin()
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByText(copy.auth.validation.emailRequired)).toBeVisible()
  await expect.element(screen.getByText(copy.auth.validation.passwordRequired)).toBeVisible()
  expect(calls).toBe(0)
})

test('signs in and lands on the dashboard placeholder with the session already applied', async () => {
  mockSuccessfulSignIn(['tickets.view'])
  const { screen, currentPath } = await openLogin()
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()
  expect(currentPath()).toBe('/acme')
})

test('returns to the page the guard interrupted', async () => {
  mockSuccessfulSignIn()
  const { screen, currentPath } = await openLogin('/acme/login?redirect=%2Facme%2Ftickets')
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.tickets.title })).toBeVisible()
  expect(currentPath()).toBe('/acme/tickets')
})

test('ignores a redirect that points off the site', async () => {
  mockSuccessfulSignIn()
  const { screen, currentPath } = await openLogin('/acme/login?redirect=%2F%2Fevil.test%2Fphish')
  await fillCredentials(screen)
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()
  expect(currentPath()).toBe('/acme')
})

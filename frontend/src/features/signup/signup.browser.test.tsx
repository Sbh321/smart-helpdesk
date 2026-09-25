import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem } from '@/test/msw/handlers'
import { validationFailed } from '@/test/msw/list'
import { renderApp } from '@/test/render-app'

/** Self sign-up on the app host (ADR-0025 §8, roadmap M6-05). */
const worker = setupMswWorker()

function signupApi() {
  const sent: Record<string, unknown>[] = []
  worker.use(
    http.get(apiUrl('/me'), () => problem(401, 'unauthenticated')),
    http.get(apiUrl('/signup/address'), ({ request }) => {
      const slug = new URL(request.url).searchParams.get('slug') ?? ''
      const reason = slug === 'acme' ? 'taken' : slug === 'admin' ? 'reserved' : null
      return HttpResponse.json({ data: { slug, available: reason === null, reason } })
    }),
    http.post(apiUrl('/signup'), async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>
      sent.push(body)
      if (body.slug === 'acme') return validationFailed({ slug: ['This address is taken. Choose another.'] })
      return HttpResponse.json({ data: { status: 'sent' } }, { status: 202 })
    }),
    http.post(apiUrl('/signup/verify'), async ({ request }) => {
      const body = (await request.json()) as { token: string }
      return body.token === 'good'
        ? HttpResponse.json(
            { data: { slug: 'himal', name: 'Himal Support', email: 'asha@himal.test' } },
            { status: 201 },
          )
        : problem(422, 'link_expired', {
            detail:
              'This link has expired or was already used. Sign up again, or sign in if the workspace exists.',
          })
    }),
  )
  return { sent }
}

async function blocking(): Promise<string[]> {
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
  const results = await axe.run(document.body)
  return results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
}

test('the address follows the workspace name and is checked as it is typed', async () => {
  signupApi()
  const { screen } = await renderApp('/signup')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.signup.title })).toBeVisible()
  expect(await blocking()).toEqual([])

  await screen.getByRole('textbox', { name: copy.signup.workspaceName }).fill('Acme')
  const address = screen.getByRole('textbox', { name: copy.signup.slug })
  await expect.element(address).toHaveValue('acme')
  await expect.element(address).toHaveAccessibleDescription(/This address is taken/)

  await address.fill('himal-support')
  await expect.element(screen.getByText(copy.signup.available)).toBeVisible()
  await expect.element(address).toHaveAccessibleDescription(/\/himal-support/)
})

test('sending the form asks the person to check their email; the honeypot stays empty', async () => {
  const { sent } = signupApi()
  const { screen } = await renderApp('/signup')
  await screen.getByRole('textbox', { name: copy.signup.name }).fill('Asha Gurung')
  await screen.getByRole('textbox', { name: copy.signup.email }).fill('asha@himal.test')
  await screen.getByLabelText(copy.signup.password, { exact: true }).fill('correct-horse-battery-9')
  await screen.getByLabelText(copy.platform.account.confirm, { exact: true }).fill('correct-horse-battery-9')
  await screen.getByRole('textbox', { name: copy.signup.workspaceName }).fill('Himal Support')
  expect(screen.getByRole('textbox', { name: copy.signup.honeypot }).query()).toBeNull()
  await screen.getByRole('button', { name: copy.signup.submit }).click()

  await expect.element(screen.getByText(/We sent a link to asha@himal.test/)).toBeVisible()
  expect(sent[0]).toMatchObject({
    name: 'Asha Gurung',
    slug: 'himal-support',
    workspace_name: 'Himal Support',
    website: '',
  })
  expect(await blocking()).toEqual([])
})

test('the email link creates the workspace and leads to its sign-in', async () => {
  signupApi()
  const { screen } = await renderApp('/signup/verify?token=good')
  await expect.element(screen.getByText(copy.signup.readyTitle)).toBeVisible()
  await expect
    .element(screen.getByRole('link', { name: 'Sign in to Himal Support' }))
    .toHaveAttribute('href', '/himal/login')
  expect(await blocking()).toEqual([])
})

test('a used or expired link says so and offers to sign up again', async () => {
  signupApi()
  const { screen } = await renderApp('/signup/verify?token=old')
  await expect.element(screen.getByText(copy.signup.expiredTitle)).toBeVisible()
  await expect
    .element(screen.getByRole('link', { name: copy.signup.startAgain }))
    .toHaveAttribute('href', '/signup')
})

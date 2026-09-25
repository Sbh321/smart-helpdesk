import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { problem } from '@/test/msw/handlers'
import { PLATFORM_ADMIN, platformState, usePlatformApi } from '@/test/msw/platform'
import { renderApp } from '@/test/render-app'

/**
 * The platform console on the admin host (ADR-0025, M6-06): sign-in, the dashboard, workspaces with
 * their subscriptions, the payments queue, plans, admins, settings and the admin's own account.
 */
const worker = setupMswWorker()

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
  usePlatformApi(worker, platformState({ signedIn: false }))
  const app = await renderApp('/platform/tenants')
  await expect.poll(() => app.currentPath()).toBe('/platform/login')
  const { screen } = app
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.platform.login.heading }))
    .toBeVisible()

  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  const email = screen.getByRole('textbox', { name: copy.auth.emailLabel })
  await expect.element(email).toHaveAttribute('aria-invalid', 'true')
  await email.fill('admin@platform.test')
  await expect.element(email).not.toHaveAttribute('aria-invalid', 'true')

  await screen.getByLabelText(copy.auth.passwordLabel).fill('wrong')
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  await expect.element(screen.getByText(copy.platform.login.invalidCredentials)).toBeVisible()
  await expect
    .element(screen.getByRole('link', { name: copy.platform.login.forgot }))
    .toHaveAttribute('href', '/platform/forgot-password')
  expect(await blocking()).toEqual([])
})

test('signing in opens the dashboard; the tabs, the docs links and the account menu work', async () => {
  const state = usePlatformApi(worker, platformState({ signedIn: false }))
  const app = await renderApp('/platform/login')
  const { screen } = app
  await screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('admin@platform.test')
  await screen.getByLabelText(copy.auth.passwordLabel).fill('password')
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform')

  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.platform.dashboard.title }))
    .toBeVisible()
  await expect.element(screen.getByText(copy.platform.dashboard.pending).first()).toBeVisible()
  await expect.element(screen.getByText('NPR 2,500.00')).toBeVisible()
  await expect
    .element(screen.getByRole('table', { name: copy.platform.dashboard.endingSoon }))
    .toMatchTextContent(/Globex.*Trial.*4 days left/)
  const nav = screen.getByRole('navigation', { name: copy.platform.nav.label })
  await expect.element(nav.getByRole('link', { name: /Payments.*1 to review/ })).toBeVisible()
  expect(await blocking()).toEqual([])

  const docs = screen.getByRole('link', { name: new RegExp(copy.platform.platformDocs) })
  expect(docs.element().getAttribute('href')).toBe('/platform/docs')
  expect(docs.element().getAttribute('target')).toBe('_blank')

  await nav.getByRole('link', { name: copy.platform.nav.plans }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform/plans')

  await screen.getByRole('button', { name: copy.nav.account }).click()
  await expect.element(screen.getByText(`Signed in as ${PLATFORM_ADMIN.name}`)).toBeVisible()
  await screen.getByRole('menuitem', { name: copy.platform.signOut }).click()
  await screen.getByRole('alertdialog').getByRole('button', { name: copy.platform.signOut }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform/login')
  expect(state.signedIn).toBe(false)
})

test('the workspaces list reads subscriptions in words, filters by them, and creates a workspace', async () => {
  const state = usePlatformApi(worker)
  const app = await renderApp('/platform/tenants')
  const { screen } = app
  const table = screen.getByRole('table', { name: copy.platform.tableLabel })
  await expect
    .element(table.getByRole('row', { name: /Acme Support/ }))
    .toMatchTextContent(/Active.*Paid.*Standard · 60 days left/)
  await expect
    .element(table.getByRole('row', { name: /Globex/ }))
    .toMatchTextContent(/Suspended.*Trial.*Free trial · 4 days left/)
  expect(table.element().textContent).not.toMatch(/\btrialing\b|\bsuspended\b/)
  expect(await blocking()).toEqual([])

  await screen.getByRole('combobox', { name: copy.platform.subscriptionFilter, exact: true }).click()
  await screen.getByRole('option', { name: copy.platform.states.trialing }).click()
  await userEvent.keyboard('{Escape}')
  await expect.poll(() => app.currentLocation().searchStr).toContain('subscription=trialing')
  await expect.element(table.getByRole('row', { name: /Acme Support/ })).not.toBeInTheDocument()

  await screen.getByRole('button', { name: copy.platform.newWorkspace.open }).click()
  const dialog = screen.getByRole('dialog', { name: copy.platform.newWorkspace.title })
  await dialog.getByRole('textbox', { name: copy.platform.newWorkspace.name }).fill('Globex')
  await expect
    .element(dialog.getByRole('textbox', { name: copy.platform.newWorkspace.slug }))
    .toHaveValue('globex')
  await dialog.getByRole('textbox', { name: copy.platform.newWorkspace.ownerEmail }).fill('owner@globex.test')
  await dialog.getByRole('button', { name: copy.platform.newWorkspace.create }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: copy.platform.newWorkspace.slug }))
    .toHaveAccessibleDescription(/The slug has already been taken/)

  await dialog.getByRole('textbox', { name: copy.platform.newWorkspace.slug }).fill('globex-retail')
  await dialog.getByRole('button', { name: copy.platform.newWorkspace.create }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform/tenants/t3')
  expect(
    state.requests.findLast((request) => request.path === '/tenants' && request.method === 'POST')?.body,
  ).toMatchObject({
    slug: 'globex-retail',
    owner_email: 'owner@globex.test',
    plan_id: 'plan-trial',
  })
})

test('a workspace page changes the subscription, records a payment and suspends after asking', async () => {
  const state = usePlatformApi(worker)
  const { screen } = await renderApp('/platform/tenants/t1')
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Acme Support' })).toBeVisible()
  await expect
    .element(screen.getByRole('region', { name: copy.platform.detail.subscription }))
    .toMatchTextContent(/Paid.*Standard · NPR 2,500.00 per month/)
  expect(await blocking()).toEqual([])

  await screen.getByRole('button', { name: copy.platform.detail.changePlan }).click()
  const change = screen.getByRole('dialog', { name: copy.platform.detail.changeTitle })
  await change.getByLabelText(copy.platform.detail.endsAt).fill('2027-03-31')
  await change.getByRole('button', { name: copy.platform.detail.save }).click()
  await expect.element(screen.getByText(copy.platform.detail.changed)).toBeVisible()
  expect(state.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
    plan_id: 'plan-standard',
    ends_at: '2027-03-31T23:59:59Z',
  })

  await screen.getByRole('button', { name: copy.platform.detail.recordPayment }).click()
  const record = screen.getByRole('dialog', { name: copy.platform.record.title })
  await record.getByRole('spinbutton', { name: copy.platform.record.periods }).fill('3')
  await expect.element(record.getByRole('textbox', { name: /Amount/ })).toHaveValue('7500.00')
  await record.getByRole('button', { name: copy.platform.record.submit }).click()
  await expect.element(screen.getByText(/Payment recorded/)).toBeVisible()
  expect(state.requests.find((request) => request.path === '/tenants/t1/payments')?.body).toMatchObject({
    periods: 3,
    amount_minor: 750000,
  })

  await screen.getByRole('button', { name: copy.platform.detail.suspend }).click()
  const suspend = screen.getByRole('dialog', { name: /Suspend Acme Support/ })
  await suspend.getByRole('textbox', { name: copy.platform.detail.suspendReason }).fill('Asked by the owner.')
  await suspend.getByRole('button', { name: copy.platform.detail.suspend }).click()
  await expect.element(screen.getByText(copy.platform.detail.suspended)).toBeVisible()
  expect(state.tenants[0]?.status).toBe('suspended')
})

test('the payments queue shows a receipt, flags a wrong amount, rejects with a reason and approves', async () => {
  const state = usePlatformApi(worker)
  const app = await renderApp('/platform/payments')
  const { screen } = app
  const table = screen.getByRole('table', { name: copy.platform.payments.tableLabel })
  await expect
    .element(table.getByRole('img', { name: /Differs from the plan price \(NPR 7,500.00\)/ }))
    .toBeInTheDocument()
  expect(await blocking()).toEqual([])

  await table.getByRole('button', { name: /Review the payment of Acme Support/ }).click()
  await expect.poll(() => app.currentLocation().searchStr).toContain('payment=pay-1')
  const review = screen.getByRole('dialog', { name: /Payment from Acme Support/ })
  await expect.element(review.getByRole('region', { name: copy.platform.payments.receipt })).toBeVisible()
  await expect.element(review).toMatchTextContent(/NPR 7,000.00/)
  expect(await blocking()).toEqual([])

  await review.getByRole('button', { name: copy.platform.payments.reject }).click()
  await review.getByRole('button', { name: copy.platform.payments.reject }).click()
  await expect
    .element(review.getByRole('textbox', { name: copy.platform.payments.rejectReason }))
    .toHaveAccessibleDescription(/at least 5 characters/)
  await review
    .getByRole('textbox', { name: copy.platform.payments.rejectReason })
    .fill('The receipt shows NPR 7,000, the plan asks 7,500.')
  await review.getByRole('button', { name: copy.platform.payments.reject }).click()
  await expect.element(screen.getByText(copy.platform.payments.rejected)).toBeVisible()
  expect(state.payments[0]?.status).toBe('rejected')

  expect(state.requests.findLast((request) => request.path === '/payments/pay-1/reject')?.body).toMatchObject(
    {
      reason: 'The receipt shows NPR 7,000, the plan asks 7,500.',
    },
  )
})

test('the link in the admin email opens the review, and approving moves the subscription on', async () => {
  const state = usePlatformApi(worker)
  const { screen } = await renderApp('/platform/payments?payment=pay-1')
  const review = screen.getByRole('dialog', { name: /Payment from Acme Support/ })
  await review.getByRole('button', { name: copy.platform.payments.approve }).click()
  await expect.element(screen.getByText(/Payment approved. Acme Support runs until 1 Mar 2027/)).toBeVisible()
  expect(state.payments[0]?.status).toBe('approved')
})

test('plans are listed with prices, created, and a second active trial is refused on its field', async () => {
  const state = usePlatformApi(worker)
  const { screen } = await renderApp('/platform/plans')
  const table = screen.getByRole('table', { name: copy.platform.plans.tableLabel })
  await expect
    .element(table.getByRole('row', { name: /Standard/ }))
    .toMatchTextContent(/Paid.*NPR 2,500.00 per month.*1 month/)
  expect(await blocking()).toEqual([])

  await screen.getByRole('button', { name: copy.platform.plans.create }).click()
  let dialog = screen.getByRole('dialog', { name: copy.platform.plans.createTitle })
  await dialog.getByRole('textbox', { name: copy.platform.plans.fields.name }).fill('Standard yearly')
  await dialog.getByRole('textbox', { name: copy.platform.plans.fields.code }).fill('standard-yearly')
  await dialog.getByRole('textbox', { name: copy.platform.plans.fields.price }).fill('25,000')
  await dialog.getByRole('spinbutton', { name: copy.platform.plans.fields.periodMonths }).fill('12')
  await dialog.getByRole('button', { name: copy.platform.plans.save }).click()
  await expect.element(screen.getByText(copy.platform.plans.saved)).toBeVisible()
  expect(state.requests.find((request) => request.path === '/plans')?.body).toMatchObject({
    code: 'standard-yearly',
    kind: 'paid',
    price_minor: 2500000,
    period_months: 12,
  })

  await screen.getByRole('button', { name: copy.platform.plans.create }).click()
  dialog = screen.getByRole('dialog', { name: copy.platform.plans.createTitle })
  await dialog.getByRole('combobox', { name: copy.platform.plans.fields.kind }).click()
  await screen.getByRole('option', { name: copy.platform.plans.kinds.trial }).click()
  await dialog.getByRole('textbox', { name: copy.platform.plans.fields.name }).fill('Long trial')
  await dialog.getByRole('textbox', { name: copy.platform.plans.fields.code }).fill('trial-30')
  await dialog.getByRole('button', { name: copy.platform.plans.save }).click()
  await expect.element(dialog.getByRole('alert')).toMatchTextContent(/Only one trial plan can be active/)
})

test('admins are invited, and another admin is deactivated after asking; you have no actions on yourself', async () => {
  const state = usePlatformApi(worker)
  const { screen } = await renderApp('/platform/admins')
  const table = screen.getByRole('table', { name: copy.platform.admins.tableLabel })
  await expect.element(table.getByRole('row', { name: /Platform Admin/ })).toMatchTextContent(/\(you\)/)
  expect(table.getByRole('button', { name: /Actions for Platform Admin/ }).query()).toBeNull()
  expect(await blocking()).toEqual([])

  await screen.getByRole('button', { name: copy.platform.admins.invite }).click()
  const invite = screen.getByRole('dialog', { name: copy.platform.admins.inviteTitle })
  await invite.getByRole('textbox', { name: copy.platform.admins.name }).fill('Rita')
  await invite.getByRole('textbox', { name: copy.platform.admins.email }).fill('sam@platform.test')
  await invite.getByRole('button', { name: copy.platform.admins.send }).click()
  await expect
    .element(invite.getByRole('textbox', { name: copy.platform.admins.email }))
    .toHaveAccessibleDescription(/already a platform admin/)
  await invite.getByRole('textbox', { name: copy.platform.admins.email }).fill('rita@platform.test')
  await invite.getByRole('button', { name: copy.platform.admins.send }).click()
  await expect.element(table.getByRole('row', { name: /Rita/ })).toMatchTextContent(/Invited/)

  await table.getByRole('button', { name: 'Actions for Sam Rai' }).click()
  await screen.getByRole('menuitem', { name: copy.platform.admins.deactivate }).click()
  await screen.getByRole('alertdialog').getByRole('button', { name: copy.platform.admins.deactivate }).click()
  await expect.element(table.getByRole('row', { name: /Sam Rai/ })).toMatchTextContent(/Deactivated/)
  expect(state.admins.find((admin) => admin.id === 'admin-2')?.status).toBe('deactivated')
})

test('settings turn sign-up off, change the grace period and say where to pay', async () => {
  const state = usePlatformApi(worker)
  const { screen } = await renderApp('/platform/settings')
  const signup = screen.getByRole('switch', { name: copy.platform.settings.signupLabel })
  await expect.element(signup).toBeChecked()
  expect(await blocking()).toEqual([])

  await signup.click()
  await screen.getByRole('spinbutton', { name: copy.platform.settings.graceLabel }).fill('61')
  await screen.getByRole('button', { name: copy.platform.settings.save }).click()
  await expect
    .element(screen.getByRole('spinbutton', { name: copy.platform.settings.graceLabel }))
    .toHaveAccessibleDescription(/between 0 and 60/)
  await screen.getByRole('spinbutton', { name: copy.platform.settings.graceLabel }).fill('10')
  await screen
    .getByRole('textbox', { name: copy.platform.settings.instructionsLabel })
    .fill('eSewa 9800000000')
  await screen.getByRole('button', { name: copy.platform.settings.save }).click()
  await expect.element(screen.getByText(copy.platform.settings.saved)).toBeVisible()
  expect(state.settings).toEqual({
    signup_enabled: false,
    grace_days: 10,
    payment_instructions: 'eSewa 9800000000',
  })
})

test('your account changes your name, and a wrong current password is said on its field', async () => {
  usePlatformApi(worker)
  const { screen } = await renderApp('/platform/account')
  await screen.getByRole('textbox', { name: copy.platform.account.name }).fill('Platform Owner')
  await screen.getByRole('button', { name: copy.platform.account.saveProfile }).click()
  await expect.element(screen.getByText(copy.platform.account.profileSaved)).toBeVisible()

  await screen.getByLabelText(copy.platform.account.current, { exact: true }).fill('nope')
  await screen
    .getByLabelText(copy.platform.account.newPassword, { exact: true })
    .fill('correct-horse-battery-9')
  await screen.getByLabelText(copy.platform.account.confirm, { exact: true }).fill('correct-horse-battery-9')
  await screen.getByRole('button', { name: copy.platform.account.savePassword }).click()
  await expect
    .element(screen.getByLabelText(copy.platform.account.current, { exact: true }))
    .toHaveAccessibleDescription(/not your current password/)
  expect(await blocking()).toEqual([])
})

test('a forgotten password: the link is asked for, then a new password is chosen', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  const forgot = await renderApp('/platform/forgot-password')
  await forgot.screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('admin@platform.test')
  await forgot.screen.getByRole('button', { name: copy.platform.recovery.send }).click()
  await expect
    .element(forgot.screen.getByText(/admin@platform.test belongs to a platform admin/))
    .toBeVisible()
  expect(await blocking()).toEqual([])
})

test('a reset link that expired says so', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  const expired = await renderApp('/platform/reset-password?token=old&email=admin%40platform.test')
  await expired.screen
    .getByLabelText(copy.platform.account.newPassword, { exact: true })
    .fill('correct-horse-battery-9')
  await expired.screen
    .getByLabelText(copy.platform.account.confirm, { exact: true })
    .fill('correct-horse-battery-9')
  await expired.screen.getByRole('button', { name: copy.platform.recovery.reset }).click()
  await expect.element(expired.screen.getByRole('alert')).toMatchTextContent(/expired or was already used/)
})

test('a good reset link sets the new password and offers sign-in', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  const reset = await renderApp('/platform/reset-password?token=good-token&email=admin%40platform.test')
  await reset.screen
    .getByLabelText(copy.platform.account.newPassword, { exact: true })
    .fill('correct-horse-battery-9')
  await reset.screen
    .getByLabelText(copy.platform.account.confirm, { exact: true })
    .fill('correct-horse-battery-9')
  await reset.screen.getByRole('button', { name: copy.platform.recovery.reset }).click()
  await expect
    .element(reset.screen.getByRole('link', { name: copy.platform.recovery.toSignIn }))
    .toBeVisible()
})

test('an expired invitation says so', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  const expired = await renderApp('/platform/accept-invitation?token=old-token')
  await expect.element(expired.screen.getByText(copy.platform.invitation.expiredTitle)).toBeVisible()
})

test('a valid invitation is accepted into the console', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  const app = await renderApp('/platform/accept-invitation?token=invite-token')
  await expect
    .element(app.screen.getByText(/invited as a platform admin with rita@platform.test/))
    .toBeVisible()
  await app.screen.getByRole('textbox', { name: copy.platform.account.name }).fill('Rita Shah')
  await app.screen
    .getByLabelText(copy.platform.account.newPassword, { exact: true })
    .fill('correct-horse-battery-9')
  await app.screen
    .getByLabelText(copy.platform.account.confirm, { exact: true })
    .fill('correct-horse-battery-9')
  expect(await blocking()).toEqual([])
  await app.screen.getByRole('button', { name: copy.platform.invitation.accept }).click()
  await expect.poll(() => app.currentPath()).toBe('/platform')
})

test('a signed-out visitor to the docs hand-off signs in and comes back to it; a failed hand-off can be retried', async () => {
  usePlatformApi(worker, platformState({ signedIn: false }))
  let calls = 0
  let next: unknown = null
  worker.use(
    http.post('*/platform-api/handoff', async ({ request }) => {
      calls += 1
      const body = (await request.json()) as { target: string; next: string }
      next = `${body.target}:${body.next}`
      return problem(503, 'service_unavailable')
    }),
  )
  const app = await renderApp('/platform/docs?next=%2Fadr%2F')
  const { screen } = app
  await expect.poll(() => app.currentPath()).toBe('/platform/login')

  await screen.getByRole('textbox', { name: copy.auth.emailLabel }).fill('admin@platform.test')
  await screen.getByLabelText(copy.auth.passwordLabel, { exact: true }).fill('password')
  await screen.getByRole('button', { name: copy.auth.login.submit }).click()

  await expect.poll(() => app.currentPath()).toBe('/platform/docs')
  await expect.element(screen.getByText(copy.platform.handoff.docs.failed)).toBeVisible()
  expect(next).toBe('docs:/adr/')

  await screen.getByRole('button', { name: /try again|retry/i }).click()
  await expect.poll(() => calls).toBe(2)
  void HttpResponse
})

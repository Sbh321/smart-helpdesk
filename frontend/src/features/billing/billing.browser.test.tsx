import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * The workspace's Billing page and the subscription banner (ADR-0025 §3, §6, roadmap M6-07): the plan
 * and its state, where to pay, a payment with its receipt, the payments sent, and the notice in the shell.
 */
const worker = setupMswWorker()
const OWNER = ['billing.manage', 'media.view', 'media.upload', 'settings.manage', 'tickets.view']
const STANDARD = {
  id: 'plan-standard',
  code: 'standard',
  name: 'Standard',
  description: null,
  kind: 'paid',
  price_minor: 250000,
  currency: 'NPR',
  period_months: 1,
  trial_days: null,
  is_active: true,
  sort_order: 1,
}
const TRIAL = {
  ...STANDARD,
  id: 'plan-trial',
  code: 'trial',
  name: 'Free trial',
  kind: 'trial',
  price_minor: 0,
  period_months: null,
  trial_days: 14,
}

type State = 'trialing' | 'active' | 'grace' | 'expired'

function subscription(state: State, daysLeft: number | null) {
  return {
    state,
    plan: state === 'trialing' ? TRIAL : STANDARD,
    ends_at: '2026-10-05T00:00:00Z',
    grace_ends_at: '2026-10-12T00:00:00Z',
    days_left: daysLeft,
    read_only: state === 'expired',
  }
}

function signIn(permissions: string[], state: State = 'trialing', daysLeft: number | null = 9) {
  const session = sessionFixture({ permissions })
  session.tenant.subscription = subscription(state, daysLeft) as typeof session.tenant.subscription
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: session })))
}

function billingApi(state: State = 'trialing') {
  const payments: unknown[] = []
  const sent: Record<string, unknown>[] = []
  worker.use(
    http.get(apiUrl('/billing'), () =>
      HttpResponse.json({
        data: {
          subscription: subscription(state, state === 'expired' ? null : 9),
          plans: [STANDARD],
          payments,
          grace_days: 7,
          payment_instructions: 'Nabil Bank, account 0123456789\nor eSewa 9800000000',
        },
      }),
    ),
    http.post(apiUrl('/billing/payments'), async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>
      sent.push(body)
      const payment = {
        id: 'pay-1',
        plan: STANDARD,
        periods: body.periods,
        amount_minor: body.amount_minor,
        currency: 'NPR',
        expected_minor: 250000 * Number(body.periods),
        paid_on: body.paid_on,
        method: body.method,
        reference: body.reference ?? null,
        note: null,
        status: 'pending',
        rejection_reason: null,
        submitted_by: { name: 'Priya', email: 'priya@acme.test' },
        recorded_by_platform: false,
        reviewed_at: null,
        period_starts_at: null,
        period_ends_at: null,
        created_at: '2026-09-25T08:00:00Z',
        receipt: {
          id: body.receipt_media_id,
          name: 'receipt.png',
          mime_type: 'image/png',
          size_bytes: 2048,
          width: null,
          height: null,
          has_thumb: false,
          has_preview: false,
        },
      }
      payments.unshift(payment)
      return HttpResponse.json({ data: payment }, { status: 201 })
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

const receipt = (name = 'receipt.png') =>
  new File([new Uint8Array(2048)], name, { type: name.endsWith('.png') ? 'image/png' : 'text/plain' })

test('the Billing page shows the plan, where to pay, and sends a payment with its receipt', async () => {
  signIn(OWNER)
  const { sent } = billingApi()
  const { screen } = await renderApp('/acme/settings/billing')
  const current = screen.getByRole('region', { name: copy.billing.current })
  await expect.element(current).toMatchTextContent(/Free trial\s*Free trial/)
  await expect.element(current).toMatchTextContent(/9 days left/)
  await expect.element(screen.getByText(/Nabil Bank, account 0123456789/)).toBeVisible()
  await expect.element(screen.getByText(copy.billing.noHistory)).toBeVisible()
  expect(await blocking()).toEqual([])

  const submit = screen.getByRole('button', { name: copy.billing.submit })
  await expect.element(submit).toBeDisabled()
  await screen.getByRole('spinbutton', { name: copy.billing.periods }).fill('3')
  await expect.element(screen.getByRole('textbox', { name: /Amount paid/ })).toHaveValue('7500.00')
  await screen.getByRole('textbox', { name: copy.billing.reference }).fill('TRX-1001')
  await userEvent.upload(screen.getByLabelText(copy.media.addFiles), receipt())
  await expect
    .element(screen.getByRole('list', { name: copy.media.uploadList }).getByText('Ready'))
    .toBeVisible()
  await submit.click()

  await expect.element(screen.getByText(copy.billing.sent)).toBeVisible()
  expect(sent[0]).toMatchObject({
    plan_id: 'plan-standard',
    periods: 3,
    amount_minor: 750000,
    method: 'bank_transfer',
    reference: 'TRX-1001',
  })
  expect(sent[0]?.receipt_media_id).toEqual(expect.any(String))
  const history = screen.getByRole('table', { name: copy.billing.historyLabel })
  await expect.element(history.getByRole('row', { name: /Standard × 3/ })).toMatchTextContent(/Being checked/)
})

test('a receipt must be an image or a PDF', async () => {
  signIn(OWNER)
  billingApi()
  const { screen } = await renderApp('/acme/settings/billing')
  await userEvent.upload(screen.getByLabelText(copy.media.addFiles), receipt('notes.txt'))
  await expect
    .element(screen.getByRole('list', { name: copy.media.uploadList }).getByText(copy.media.receiptOnly))
    .toBeVisible()
  await expect.element(screen.getByRole('button', { name: copy.billing.submit })).toBeDisabled()
})

test('Billing is for owners and admins only', async () => {
  signIn(['tickets.view', 'settings.manage'])
  const { screen } = await renderApp('/acme/settings/general')
  await expect.element(screen.getByRole('link', { name: copy.workspaceSettings.general })).toBeVisible()
  expect(screen.getByRole('link', { name: copy.billing.nav }).query()).toBeNull()
})

test('the shell says when the trial ends soon, and the owner can open billing', async () => {
  signIn(OWNER, 'trialing', 3)
  billingApi()
  const { screen, currentPath } = await renderApp('/acme')
  const banner = screen.getByRole('region', { name: copy.billing.banner.label })
  await expect.element(banner).toMatchTextContent(/Your free trial ends in 3 days/)
  expect(await blocking()).toEqual([])
  await banner.getByRole('link', { name: copy.billing.banner.action }).click()
  await expect.poll(() => currentPath()).toBe('/acme/settings/billing')
})

test('a read-only workspace says so, and someone without billing is told whom to ask', async () => {
  signIn(['tickets.view'], 'expired', null)
  const { screen } = await renderApp('/acme')
  const banner = screen.getByRole('region', { name: copy.billing.banner.label })
  await expect.element(banner).toMatchTextContent(/read-only/)
  await expect.element(banner).toMatchTextContent(copy.billing.banner.askOwner)
  expect(banner.getByRole('link').query()).toBeNull()
})

test('no banner while the subscription has more than a week left', async () => {
  signIn(OWNER, 'active', 40)
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('main')).toBeVisible()
  expect(screen.getByRole('region', { name: copy.billing.banner.label }).query()).toBeNull()
})

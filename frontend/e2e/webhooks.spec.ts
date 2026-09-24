import { type APIRequestContext, expect, type Page, test } from '@playwright/test'
import { echoInternalUrl, echoUrl, stamp, stateFor, users } from './support/env'
import { expectOk, first, sessionApi, ticketDefaults } from './support/session'

/**
 * E2E-10, webhook delivery (docs/07-api/webhooks.md): Meera adds a subscription to webhook-echo in
 * Settings → Webhooks, a new ticket produces a signed `ticket.created` delivery that webhook-echo
 * verifies with the secret shown once, and a delivery the receiver refused with 500 succeeds after a
 * manual retry. webhook-echo's control endpoints (tools/webhook-echo/echo.mjs) give this run's path its
 * own secret and forced status and return what it received.
 */

test.use({ storageState: stateFor('meera') })

type EchoRecord = {
  verified: boolean
  reason: string
  answered: number
  event_type: string
  delivery_id: string
  body: string
}

async function configureReceiver(request: APIRequestContext, name: string, secret: string, status = 0) {
  await expectOk(await request.put(`${echoUrl}/_echo/receivers/${name}`, { data: { secret, status } }))
}

async function received(request: APIRequestContext, name: string): Promise<EchoRecord[]> {
  const response = await request.get(`${echoUrl}/_echo/deliveries?path=/hook/${name}`)
  await expectOk(response)
  return (await response.json()).data
}

/** The delivery log does not poll: reopen it until the row for the event reaches the state. */
async function expectDelivery(
  page: Page,
  webhook: string,
  event: string,
  state: string | RegExp,
  response: string,
) {
  const log = page.getByRole('table', { name: `Deliveries of ${webhook}` })
  await expect(async () => {
    await page.reload()
    await page.getByRole('button', { name: `Show deliveries of ${webhook}` }).click()
    const row = log.getByRole('row').filter({ hasText: event }).first()
    await expect(row).toContainText(state, { timeout: 1_000 })
    await expect(row).toContainText(response, { timeout: 1_000 })
  }).toPass({ timeout: 30_000, intervals: [500, 1_000, 2_000] })
  return log.getByRole('row').filter({ hasText: event }).first()
}

test('a subscription receives a signed delivery that webhook-echo verifies, and a refused one is retried', async ({
  page,
  request,
}) => {
  const run = stamp()
  const receiver = `e2e-${run}`
  const name = `E2E hook ${run}`

  // Add the webhook through the form.
  await page.goto(`/${users.meera.workspace}/settings/webhooks`)
  await page.getByRole('button', { name: 'Add webhook' }).click()
  const dialog = page.getByRole('dialog', { name: 'Add a webhook' })
  await dialog.getByLabel('Name').fill(name)
  await dialog.getByLabel('Endpoint URL').fill(`${echoInternalUrl}/hook/${receiver}`)
  await dialog.getByRole('checkbox', { name: /^ticket\.created:/ }).check()
  await dialog.getByRole('button', { name: 'Add webhook' }).click()

  // The secret is shown once; the receiver gets it before any delivery is made.
  const secret = await page.getByRole('textbox', { name: 'Signing secret' }).inputValue()
  expect(secret.length).toBeGreaterThan(20)
  await configureReceiver(request, receiver, secret)
  await page.getByRole('button', { name: 'I have stored the secret' }).click()
  await expect(page.getByRole('dialog')).toBeHidden()
  const row = page.getByRole('row').filter({ hasText: name })
  await expect(row).toContainText('Active')
  await expect(row).toContainText('ticket.created')

  // A domain event: a new ticket.
  const api = sessionApi(page.context())
  const title = `Webhook E2E ${run}`
  const created = await api.post('/tickets', {
    title,
    description: 'Created by the webhook end-to-end test.',
    ...(await ticketDefaults(api)),
    impact: 2,
    urgency: 2,
  })
  await expectOk(created)
  const ticketId: string = (await created.json()).data.id

  await expectDelivery(page, name, 'ticket.created', 'Delivered', '204')
  const verified = (await received(request, receiver)).filter(
    (record) => record.event_type === 'ticket.created',
  )
  expect(verified).toHaveLength(1)
  const delivery = first(verified, 'a ticket.created delivery')
  expect(delivery).toMatchObject({ verified: true, reason: 'signature verified', answered: 204 })
  expect(delivery.body).toContain(ticketId)
  expect(delivery.body).toContain(title)

  // The receiver fails: a test delivery is refused with 500, then retried by hand once it works again.
  await configureReceiver(request, receiver, secret, 500)
  await page.getByRole('button', { name: `Actions for ${name}` }).click()
  await page.getByRole('menuitem', { name: 'Send test' }).click()
  await expect(page.getByText(`Test delivery queued for ${name}.`)).toBeVisible()
  // A test delivery is not rescheduled, so a refusal leaves it dead at once; a failed event would wait
  // for its automatic retry. Either way the retry button is offered.
  // The status reads in words since M4-11: a refused delivery is retrying (or has failed / given up).
  await expectDelivery(page, name, 'ping', /Retrying|Failed|Gave up/, '500')

  await configureReceiver(request, receiver, secret, 0)
  await page.getByRole('button', { name: 'Retry delivery ping' }).click()
  await expect(page.getByText('Delivery queued again.')).toBeVisible()
  const retried = await expectDelivery(page, name, 'ping', 'Delivered', '204')
  await expect(retried.getByRole('button', { name: /^Retry/ })).toHaveCount(0)

  const pings = (await received(request, receiver)).filter((record) => record.event_type === 'ping')
  expect(pings.map((record) => [record.verified, record.answered])).toEqual([
    [true, 500],
    [true, 204],
  ])
  expect(new Set(pings.map((record) => record.delivery_id)).size).toBe(1)
})

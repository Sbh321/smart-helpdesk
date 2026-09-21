import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { TEST_ROTATED_SECRET, TEST_WEBHOOK_SECRET, webhookDb } from '@/test/msw/webhooks'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.webhooks

async function openWebhooks(permissions = ['tickets.view', 'integrations.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp('/acme/settings/webhooks')
  await expect.element(app.screen.getByRole('heading', { level: 2, name: text.title })).toBeVisible()
  return app
}

test('lists webhooks with their events and status, an auto-disabled one marked as such', async () => {
  const { screen } = await openWebhooks()
  const table = screen.getByRole('table', { name: text.listLabel })
  await expect
    .element(table.getByRole('row', { name: /CRM sync/ }).getByText('ticket.resolved'))
    .toBeVisible()
  await expect.element(table.getByRole('row', { name: /CRM sync/ }).getByText(text.active)).toBeVisible()
  await expect
    .element(table.getByRole('row', { name: /Old chat bot/ }).getByText(text.disabledAuto))
    .toBeVisible()
})

test('creates a webhook with an events checklist and shows the secret exactly once', async () => {
  const { screen } = await openWebhooks()
  await screen.getByRole('button', { name: text.create }).click()
  const dialog = screen.getByRole('dialog', { name: text.createTitle })
  await expect.element(dialog.getByRole('group', { name: text.events })).toBeVisible()

  await dialog.getByRole('button', { name: text.save }).click()
  await expect.element(dialog.getByText(text.validation.events)).toBeVisible()

  await dialog.getByRole('textbox', { name: text.name }).fill('Status page')
  await dialog.getByRole('textbox', { name: text.url }).fill('https://10.0.0.5/hook')
  await dialog.getByRole('checkbox', { name: /^ticket\.resolved/ }).click()
  await dialog.getByRole('checkbox', { name: /^ticket\.closed/ }).click()
  await dialog.getByRole('button', { name: text.save }).click()
  // The API's SSRF guard answers on the URL field.
  await expect.element(dialog.getByText(/private, loopback or reserved address/)).toBeVisible()

  await dialog.getByRole('textbox', { name: text.url }).fill('https://status.example.com/hooks')
  await dialog.getByRole('button', { name: text.save }).click()

  const secretDialog = screen.getByRole('dialog', { name: 'Status page' })
  await expect.element(secretDialog.getByText(text.secretTitle).first()).toBeVisible()
  await expect
    .element(secretDialog.getByRole('textbox', { name: text.secret }))
    .toHaveValue(TEST_WEBHOOK_SECRET)
  await expect
    .poll(() => webhookDb.webhooks.find((webhook) => webhook.name === 'Status page')?.events)
    .toEqual(['ticket.resolved', 'ticket.closed'])

  await secretDialog.getByRole('button', { name: text.done }).click()
  await expect.element(secretDialog).not.toBeInTheDocument()
  await expect.element(screen.getByRole('row', { name: /Status page/ })).toBeVisible()
  // The secret is gone for good: it is not in the page and the list never carries it.
  expect(document.body.textContent).not.toContain(TEST_WEBHOOK_SECRET)
  expect(document.querySelector(`input[value="${TEST_WEBHOOK_SECRET}"]`)).toBeNull()
})

test('shows the delivery log and retries a dead delivery', async () => {
  const { screen } = await openWebhooks()
  await screen.getByRole('button', { name: fill(text.showDeliveriesNamed, { name: 'CRM sync' }) }).click()
  const log = screen.getByRole('table', { name: fill(text.deliveries.label, { name: 'CRM sync' }) })
  const dead = log.getByRole('row', { name: /ticket\.created/ })
  await expect.element(dead.getByText(text.deliveries.states.dead)).toBeVisible()
  await expect.element(dead.getByText('503')).toBeVisible()
  await expect.element(dead.getByRole('cell', { name: '6', exact: true })).toBeVisible()
  // Succeeded deliveries have nothing to retry.
  expect(
    log.getByRole('button', { name: fill(text.deliveries.retryNamed, { event: 'ticket.resolved' }) }).query(),
  ).toBeNull()

  await dead
    .getByRole('button', { name: fill(text.deliveries.retryNamed, { event: 'ticket.created' }) })
    .click()
  await expect.element(dead.getByText(text.deliveries.states.pending)).toBeVisible()
  expect(webhookDb.deliveries.find((item) => item.event_type === 'ticket.created')?.manual_retries).toBe(1)
})

test('sends a test delivery that appears in the log', async () => {
  const { screen } = await openWebhooks()
  await screen.getByRole('button', { name: fill(text.actionsFor, { name: 'CRM sync' }) }).click()
  await screen.getByRole('menuitem', { name: text.sendTest }).click()
  const log = screen.getByRole('table', { name: fill(text.deliveries.label, { name: 'CRM sync' }) })
  await expect.element(log.getByRole('row', { name: /ping/ })).toBeVisible()
})

test('disables a webhook after confirmation and enables it again', async () => {
  const { screen } = await openWebhooks()
  const row = screen.getByRole('row', { name: /CRM sync/ })
  await screen.getByRole('button', { name: fill(text.actionsFor, { name: 'CRM sync' }) }).click()
  await screen.getByRole('menuitem', { name: text.disable }).click()
  const confirm = screen.getByRole('alertdialog', { name: text.disableTitle })
  await confirm.getByRole('button', { name: text.disable }).click()

  await expect.element(confirm).not.toBeInTheDocument()
  await expect.element(row.getByText(text.disabled, { exact: true })).toBeVisible()
  expect(webhookDb.webhooks.find((webhook) => webhook.name === 'CRM sync')?.is_active).toBe(false)

  await screen.getByRole('button', { name: fill(text.actionsFor, { name: 'CRM sync' }) }).click()
  await screen.getByRole('menuitem', { name: text.enable }).click()
  await expect.element(row.getByText(text.active)).toBeVisible()
})

test('rotates the secret and shows the new one once', async () => {
  const { screen } = await openWebhooks()
  await screen.getByRole('button', { name: fill(text.actionsFor, { name: 'CRM sync' }) }).click()
  await screen.getByRole('menuitem', { name: text.rotate }).click()
  await screen
    .getByRole('alertdialog', { name: text.rotateTitle })
    .getByRole('button', { name: text.rotate })
    .click()

  const secretDialog = screen.getByRole('dialog', { name: 'CRM sync' })
  await expect.element(secretDialog.getByText(text.rotatedDescription)).toBeVisible()
  await expect
    .element(secretDialog.getByRole('textbox', { name: text.secret }))
    .toHaveValue(TEST_ROTATED_SECRET)
  await secretDialog.getByRole('button', { name: text.done }).click()
  expect(document.body.textContent).not.toContain(TEST_ROTATED_SECRET)
})

test('is forbidden without integrations.manage and hidden from the settings navigation', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'settings.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/webhooks')
  await expect.element(screen.getByRole('navigation', { name: copy.settings.sections })).toBeVisible()
  expect(screen.getByRole('link', { name: text.nav }).query()).toBeNull()
  expect(screen.getByRole('heading', { level: 2, name: text.title }).query()).toBeNull()
})

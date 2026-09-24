import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { TEST_CLIENT_SECRET } from '@/test/msw/api-clients'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.apiClients

async function openApiClients(permissions = ['tickets.view', 'integrations.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp('/acme/settings/api-clients')
  await expect.element(app.screen.getByRole('heading', { level: 2, name: text.title })).toBeVisible()
  return app
}

test('lists the clients with their scopes and status, revoked ones without a revoke button', async () => {
  const { screen } = await openApiClients()
  const table = screen.getByRole('table', { name: text.listLabel })
  await expect.element(table.getByRole('row', { name: /Monitoring/ })).toBeVisible()
  await expect
    .element(table.getByRole('row', { name: /Monitoring/ }).getByText('tickets:write'))
    .toBeVisible()
  // Scopes read in words beside their code (M4-11).
  await expect
    .element(table.getByRole('row', { name: /Monitoring/ }).getByText('Create tickets', { exact: true }))
    .toBeVisible()
  await expect.element(table.getByRole('row', { name: /Old CRM sync/ }).getByText(text.revoked)).toBeVisible()
  await expect.element(screen.getByRole('button', { name: 'Revoke Monitoring' })).toBeVisible()
  expect(screen.getByRole('button', { name: 'Revoke Old CRM sync' }).query()).toBeNull()
})

test('creates a client with scope checkboxes and shows the secret exactly once', async () => {
  const { screen } = await openApiClients()
  await screen.getByRole('button', { name: text.create }).click()
  const dialog = screen.getByRole('dialog', { name: text.createTitle })
  await expect.element(dialog.getByRole('group', { name: text.scopes })).toBeVisible()

  await dialog.getByRole('button', { name: text.save }).click()
  await expect.element(dialog.getByText(text.validation.scopes)).toBeVisible()
  await expect
    .element(dialog.getByRole('textbox', { name: text.name }))
    .toHaveAttribute('aria-invalid', 'true')

  await dialog.getByRole('textbox', { name: text.name }).fill('Nagios')
  await dialog.getByRole('checkbox', { name: /^tickets:write/ }).click()
  await dialog.getByRole('checkbox', { name: /^contacts:read/ }).click()
  await dialog.getByRole('button', { name: text.save }).click()

  const secretDialog = screen.getByRole('dialog', { name: 'Nagios' })
  await expect.element(secretDialog.getByText(text.secretTitle).first()).toBeVisible()
  await expect
    .element(secretDialog.getByRole('textbox', { name: text.clientSecret }))
    .toHaveValue(TEST_CLIENT_SECRET)
  await expect.element(secretDialog.getByRole('button', { name: `Copy ${text.clientSecret}` })).toBeVisible()
  await expect
    .poll(() => db.apiClients.find((client) => client.name === 'Nagios')?.scopes)
    .toEqual(['tickets:write', 'contacts:read'])

  await secretDialog.getByRole('button', { name: text.done }).click()
  await expect.element(secretDialog).not.toBeInTheDocument()
  await expect.element(screen.getByRole('row', { name: /Nagios/ })).toBeVisible()
  // The secret is gone for good: it is not in the page and the list never carries it.
  expect(document.body.textContent).not.toContain(TEST_CLIENT_SECRET)
  expect(document.querySelector(`input[value="${TEST_CLIENT_SECRET}"]`)).toBeNull()
})

test('revokes a client after confirmation', async () => {
  const { screen } = await openApiClients()
  await screen.getByRole('button', { name: 'Revoke Monitoring' }).click()
  const confirm = screen.getByRole('alertdialog', { name: text.revokeTitle })
  await expect.element(confirm.getByText(/stops working immediately/)).toBeVisible()
  await confirm.getByRole('button', { name: text.revoke }).click()

  await expect.poll(() => db.apiClients.find((client) => client.name === 'Monitoring')?.revoked).toBe(true)
  await expect.element(confirm).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Revoke Monitoring' }).query()).toBeNull()
})

test('is forbidden without integrations.manage and hidden from the settings navigation', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'settings.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/api-clients')
  await expect.element(screen.getByRole('navigation', { name: copy.settings.sections })).toBeVisible()
  expect(screen.getByRole('link', { name: text.nav }).query()).toBeNull()
  expect(screen.getByRole('heading', { level: 2, name: text.title }).query()).toBeNull()
})

test('after the one-time reveal the secret is in no page and no cache, even after navigating away (M4-11)', async () => {
  const { screen, router, queryClient } = await openApiClients()
  await screen.getByRole('button', { name: text.create }).click()
  const dialog = screen.getByRole('dialog', { name: text.createTitle })
  await dialog.getByRole('textbox', { name: text.name }).fill('Zabbix')
  await dialog.getByRole('checkbox', { name: /^tickets:read/ }).click()
  await dialog.getByRole('button', { name: text.save }).click()
  const secretDialog = screen.getByRole('dialog', { name: 'Zabbix' })
  await expect
    .element(secretDialog.getByRole('textbox', { name: text.clientSecret }))
    .toHaveValue(TEST_CLIENT_SECRET)
  await secretDialog.getByRole('button', { name: text.done }).click()
  await expect.element(secretDialog).not.toBeInTheDocument()

  await router.navigate({ to: '/$workspace/settings/webhooks', params: { workspace: 'acme' } })
  await router.navigate({ to: '/$workspace/settings/api-clients', params: { workspace: 'acme' } })
  await expect.element(screen.getByRole('row', { name: /Zabbix/ })).toBeVisible()
  expect(document.body.innerHTML).not.toContain(TEST_CLIENT_SECRET)
  const cached = JSON.stringify(
    queryClient
      .getQueryCache()
      .getAll()
      .map((query) => query.state.data),
  )
  expect(cached).not.toContain(TEST_CLIENT_SECRET)
  // A finished mutation keeps its result for five minutes by default; the create response carries the
  // secret, so it must not outlive the dialog.
  const mutations = JSON.stringify(
    queryClient
      .getMutationCache()
      .getAll()
      .map((mutation) => mutation.state.data),
  )
  expect(mutations).not.toContain(TEST_CLIENT_SECRET)
})

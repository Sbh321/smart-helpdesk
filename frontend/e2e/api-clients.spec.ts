import { type APIRequestContext, expect, test } from '@playwright/test'
import { apiUrl, stamp, stateFor, users } from './support/env'
import { expectOk } from './support/session'

/**
 * E2E-11, API client (docs/07-api/authentication.md): Meera creates a client in Settings → API clients,
 * an integration gets a token with the client-credentials grant and creates a ticket, the ticket shows
 * in the UI as created via the API, revoking the client rejects its token at once, and the audit log
 * shows both actions (M3-03).
 */

test.use({ storageState: stateFor('meera') })

async function token(request: APIRequestContext, clientId: string, secret: string) {
  return request.post(`${apiUrl}/oauth/token`, {
    form: {
      grant_type: 'client_credentials',
      client_id: clientId,
      client_secret: secret,
      scope: 'tickets:read tickets:write contacts:read catalog:read',
    },
  })
}

test('a client created in Settings gets a token, creates a ticket, and stops working when revoked', async ({
  page,
  playwright,
}) => {
  const run = stamp()
  const name = `E2E integration ${run}`
  const workspace = users.meera.workspace

  await page.goto(`/${workspace}/settings/api-clients`)
  await page.getByRole('button', { name: 'Create API client' }).click()
  const dialog = page.getByRole('dialog', { name: 'Create an API client' })
  await dialog.getByLabel('Name').fill(name)
  for (const scope of ['tickets:read', 'tickets:write', 'contacts:read', 'catalog:read']) {
    await dialog.getByRole('checkbox', { name: new RegExp(`^${scope}:`) }).check()
  }
  await dialog.getByRole('button', { name: 'Create client' }).click()
  const clientId = await page.getByRole('textbox', { name: 'Client id' }).inputValue()
  const secret = await page.getByRole('textbox', { name: 'Client secret' }).inputValue()
  expect(clientId).not.toBe('')
  expect(secret).not.toBe('')
  await page.getByRole('button', { name: 'I have stored the secret' }).click()
  await expect(page.getByRole('row').filter({ hasText: name })).toContainText('Active')

  // The integration: its own request context, no cookies, only the bearer token.
  const integration = await playwright.request.newContext({ ignoreHTTPSErrors: true })
  try {
    const issued = await token(integration, clientId, secret)
    await expectOk(issued)
    const { access_token: accessToken, token_type: tokenType } = await issued.json()
    expect(tokenType).toBe('Bearer')
    const headers = { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' }

    const contacts = await integration.get(`${apiUrl}/v1/contacts?per_page=1`, { headers })
    await expectOk(contacts)
    const categories = await integration.get(`${apiUrl}/v1/categories`, { headers })
    await expectOk(categories)
    const contactId: string = (await contacts.json()).data[0].id
    const categoryId: string = (await categories.json()).data[0].id
    const title = `API client E2E ${run}: disk full on db-01`
    const create = () =>
      integration.post(`${apiUrl}/v1/tickets`, {
        headers: { ...headers, 'Idempotency-Key': `e2e-${run}` },
        data: {
          title,
          description: 'Raised by the monitoring integration.',
          contact_id: contactId,
          category_id: categoryId,
          impact: 2,
          urgency: 3,
        },
      })
    const created = await create()
    expect(created.status(), await created.text()).toBe(201)
    const ticket = (await created.json()).data
    expect(ticket.created_via).toBe('api')
    // A replayed Idempotency-Key returns the same ticket instead of a second one.
    expect((await (await create()).json()).data.id).toBe(ticket.id)

    // The ticket in the UI.
    await page.goto(`/${workspace}/tickets`)
    await page.getByRole('searchbox').first().fill(run)
    await page.getByRole('cell', { name: `#${ticket.number}`, exact: true }).click()
    await expect(page.getByRole('heading', { level: 1 })).toContainText(title)
    // The channel reads as its label ("API"), not the stored value, since M4-05.
    await expect(page.getByRole('definition').filter({ hasText: /^API$/ })).toBeVisible()

    // Revoke: the token is refused on its next request.
    await page.goto(`/${workspace}/settings/api-clients`)
    await page.getByRole('button', { name: `Revoke ${name}` }).click()
    await page.getByRole('alertdialog').getByRole('button', { name: 'Revoke' }).click()
    await expect(page.getByText(`API client ${name} revoked.`)).toBeVisible()
    await expect(page.getByRole('row').filter({ hasText: name })).toContainText('Revoked')
    expect((await integration.get(`${apiUrl}/v1/tickets/${ticket.id}`, { headers })).status()).toBe(401)
    expect((await token(integration, clientId, secret)).status()).toBe(401)
  } finally {
    await integration.dispose()
  }

  // The audit log names both actions on this client.
  await page.goto(`/${workspace}/settings/audit`)
  const entries = page.getByRole('table', { name: 'Audit entries' })
  await expect(
    entries.getByRole('row').filter({ hasText: 'API client created' }).filter({ hasText: name }),
  ).toBeVisible()
  await expect(
    entries.getByRole('row').filter({ hasText: 'API client revoked' }).filter({ hasText: name }),
  ).toBeVisible()
})

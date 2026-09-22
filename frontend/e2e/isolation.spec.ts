import { type Browser, expect, test } from '@playwright/test'
import { stateFor, users } from './support/env'
import { expectOk, first, sessionApi } from './support/session'

/**
 * E2E-08, tenant isolation negative (FR-TEN-03, NFR-SEC-01): a Globex admin who knows an Acme ticket's
 * id sees the not-found page at once, the API answers 404 with nothing of the ticket in the body, and the
 * Acme workspace URL does not switch the session to Acme.
 */

type Ticket = { id: string; number: number; title: string }

test.use({ storageState: stateFor('sam') })

async function acmeTicket(browser: Browser): Promise<Ticket> {
  const context = await browser.newContext({ storageState: stateFor('meera'), ignoreHTTPSErrors: true })
  try {
    const list = await sessionApi(context).json<{ data: Ticket[] }>('/tickets?per_page=1')
    return first(list.data, 'an Acme ticket')
  } finally {
    await context.close()
  }
}

test('a Globex admin cannot open an Acme ticket in the UI or through the API', async ({ page, browser }) => {
  const ticket = await acmeTicket(browser)
  const globex = users.sam.workspace

  // The API: 404 problem details, the same as for an id that never existed, and no Acme data.
  const api = sessionApi(page.context())
  const me = await api.json<{ data: { tenant: { slug: string } } }>('/me')
  expect(me.data.tenant.slug).toBe(globex)
  const response = await api.get(`/tickets/${ticket.id}`)
  expect(response.status()).toBe(404)
  expect(response.headers()['content-type']).toContain('application/problem+json')
  const body = await response.text()
  expect(body).not.toContain(ticket.title)
  expect(body.toLowerCase()).not.toContain('acme')
  const unknown = await api.get('/tickets/019a0000-0000-7000-8000-000000000000')
  expect(unknown.status()).toBe(404)
  expect(JSON.parse(body).code).toBe((await unknown.json()).code)

  // Nor through the list and its search.
  const found = await api.json<{ data: Ticket[] }>(`/tickets?search=${encodeURIComponent(ticket.title)}`)
  expect(found.data.map((row) => row.id)).not.toContain(ticket.id)

  // The UI: the not-found state without retries (4xx are never retried, query-client.ts).
  const started = Date.now()
  await page.goto(`/${globex}/tickets/${ticket.id}`)
  await expect(page.getByRole('heading', { name: 'This page does not exist' })).toBeVisible({
    timeout: 5_000,
  })
  expect(Date.now() - started, 'not-found should show within 5 s').toBeLessThan(5_000)
  await expect(page.getByText(ticket.title)).toHaveCount(0)

  // The Acme workspace URL does not switch workspaces: the session's workspace wins (ADR-0021), so the
  // page moves to the same path in Globex and shows the same not-found state.
  await page.goto(`/${users.meera.workspace}/tickets/${ticket.id}`)
  await expect(page).toHaveURL(new RegExp(`/${globex}/tickets/${ticket.id}$`))
  await expect(page.getByRole('heading', { name: 'This page does not exist' })).toBeVisible({
    timeout: 5_000,
  })
  await expect(page.getByText(ticket.title)).toHaveCount(0)
})

test('the Globex ticket list holds only Globex tickets', async ({ page, browser }) => {
  const ticket = await acmeTicket(browser)
  await page.goto(`/${users.sam.workspace}/tickets`)
  await expect(page.getByRole('heading', { level: 1, name: 'Tickets' })).toBeVisible()
  await page.getByRole('searchbox').first().fill(ticket.title)
  await expect(page.getByRole('cell', { name: `#${ticket.number}`, exact: true })).toHaveCount(0)
  await expect(page.getByText(ticket.title)).toHaveCount(0)

  // The session's workspace is Globex whatever the page asks for.
  const me = await sessionApi(page.context()).get('/me')
  await expectOk(me)
  expect((await me.json()).data.tenant.slug).toBe(users.sam.workspace)
})

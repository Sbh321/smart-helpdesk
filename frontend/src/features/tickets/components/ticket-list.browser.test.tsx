import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { CATEGORY_FIXTURES, TICKET_FIXTURES } from '@/test/msw/tickets'
import { renderApp } from '@/test/render-app'

/** Roadmap M1-17: the ticket list on the DataTable, its URL round trip and the detail placeholder. */
const worker = setupMswWorker()
const requests: URL[] = []

beforeEach(() => {
  requests.length = 0
  worker.events.on('request:start', ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname === '/v1/tickets') requests.push(url)
  })
})
afterEach(() => worker.events.removeAllListeners())

async function openTickets(path = '/acme/tickets') {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'tickets.create'] }) }),
    ),
  )
  const app = await renderApp(path)
  return { ...app, table: app.screen.getByRole('table', { name: copy.tickets.list.label }) }
}

test('the list opens on the two-field default order and embeds contact and category', async () => {
  const { table } = await openTickets()
  const first = TICKET_FIXTURES[0]

  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(new RegExp(`#${first?.number}`))
  await expect
    .element(table.getByRole('columnheader', { name: copy.tickets.columns.priority }))
    .toHaveAttribute('aria-sort', 'descending')
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(/P1 Critical/)
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(/Aarav Adhikari/)
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(/Billing/)
  const request = requests.at(-1)
  expect(request?.searchParams.get('sort')).toBe('-priority_score,-created_at')
  expect(request?.searchParams.get('include')).toBe('contact,category')
})

test('the priority header flips between ascending and descending, and other headers sort by their field', async () => {
  const { table, currentLocation } = await openTickets()
  const priority = table.getByRole('columnheader', { name: copy.tickets.columns.priority })
  await expect.element(priority).toHaveAttribute('aria-sort', 'descending')

  await priority.getByRole('button').click()
  await expect.element(priority).toHaveAttribute('aria-sort', 'ascending')
  expect(currentLocation().search).toEqual({ sort: 'priority_score' })

  await priority.getByRole('button').click()
  await expect.element(priority).toHaveAttribute('aria-sort', 'descending')
  expect(currentLocation().search).toEqual({ sort: '-priority_score' })

  const number = table.getByRole('columnheader', { name: copy.tickets.columns.number })
  await number.getByRole('button').click()
  await expect.element(number).toHaveAttribute('aria-sort', 'ascending')
  await expect.element(priority).toHaveAttribute('aria-sort', 'none')
  expect(requests.at(-1)?.searchParams.get('sort')).toBe('number')
})

test('status (with the active alias), priority, category and date filters round-trip through the URL', async () => {
  const billing = CATEGORY_FIXTURES[0]?.id ?? ''
  const { screen, table, currentLocation, router } = await openTickets(
    `/acme/tickets?category_id=${billing}&created_between=2026-09-01,2026-09-06`,
  )
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  let request = requests.at(-1)
  expect(request?.searchParams.get('filter[category_id]')).toBe(billing)
  expect(request?.searchParams.get('filter[created_between]')).toBe('2026-09-01,2026-09-06')
  await expect
    .element(screen.getByRole('button', { name: /Created.*2026/ }))
    .toMatchTextContent(/1 Sept? 2026 – 6 Sept? 2026/)

  await screen.getByRole('combobox', { name: copy.tickets.list.statusFilter }).click()
  await screen.getByRole('option', { name: copy.tickets.list.activeOption }).click()
  await userEvent.keyboard('{Escape}')
  await screen.getByRole('combobox', { name: copy.tickets.list.priorityFilter }).click()
  await screen.getByRole('option', { name: copy.tickets.priority.P1 }).click()
  await userEvent.keyboard('{Escape}')

  await expect.poll(() => currentLocation().search).toMatchObject({ status: 'active', priority: 'P1' })
  request = requests.at(-1)
  expect(request?.searchParams.get('filter[status]')).toBe('active')
  expect(request?.searchParams.get('filter[priority]')).toBe('P1')
  for (const row of table.getByRole('row').elements().slice(1)) {
    expect(row.textContent).toMatch(/P1 Critical/)
    expect(row.textContent).not.toMatch(/Resolved|Closed/)
  }

  router.history.back()
  await expect.poll(() => currentLocation().search).not.toHaveProperty('priority')
  expect(currentLocation().search).toMatchObject({ status: 'active' })
})

test('searching sends `search` and resets the page', async () => {
  const { screen, currentLocation } = await openTickets('/acme/tickets?page=2')
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 26, to: 50, total: 60 })))
    .toBeVisible()

  await screen.getByRole('searchbox', { name: copy.tickets.list.searchLabel }).fill('invoice')

  await expect.poll(() => currentLocation().search).toEqual({ search: 'invoice' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('search')).toBe('invoice')
  expect(requests.at(-1)?.searchParams.get('page')).toBe('1')
})

test('Enter on a row opens the ticket with its fields and history', async () => {
  const { table, screen, currentPath } = await openTickets()
  const first = TICKET_FIXTURES[0]
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  ;(table.getByRole('row').nth(1).element() as HTMLElement).focus()
  await userEvent.keyboard('{Enter}')

  await expect
    .element(screen.getByRole('heading', { level: 1, name: `#${first?.number} ${first?.title}` }))
    .toBeVisible()
  expect(currentPath()).toBe(`/acme/tickets/${first?.id}`)
  await expect
    .element(screen.getByRole('heading', { level: 2, name: copy.tickets.detail.history }))
    .toBeVisible()
  await expect
    .element(
      screen.getByText(
        new RegExp(fill(copy.tickets.detail.event, { type: 'created', actor: copy.tickets.detail.user })),
      ),
    )
    .toBeVisible()

  await screen.getByRole('link', { name: copy.tickets.detail.back }).click()
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.tickets.title })).toBeVisible()
})

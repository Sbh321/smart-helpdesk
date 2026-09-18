import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { columnVisibilityKey } from '@/components/shared/data-table'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { CONTACT_FIXTURES, contactsHandler, ORGANIZATION_FIXTURES } from '@/test/msw/contacts'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/**
 * Roadmap M1-14 acceptance, exercised through the real router: sort, page and filters round-trip
 * through the URL, back/forward restores the list, and the API receives the contract's parameters.
 */
const worker = setupMswWorker()
const contactRequests: URL[] = []

beforeEach(() => {
  localStorage.removeItem(columnVisibilityKey('contacts'))
  contactRequests.length = 0
  worker.events.on('request:start', ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname === '/v1/contacts') contactRequests.push(url)
  })
})

afterEach(() => {
  worker.events.removeAllListeners()
})

const ACME = ORGANIZATION_FIXTURES[0]?.id ?? ''

async function openContacts(path = '/acme/contacts') {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['contacts.view'] }) }),
    ),
  )
  const app = await renderApp(path)
  const table = app.screen.getByRole('table', { name: copy.contacts.list.label })
  return { ...app, table }
}

function dataRow(
  table: ReturnType<Awaited<ReturnType<typeof openContacts>>['screen']['getByRole']>,
  index: number,
) {
  // Row 0 is the header row.
  return table.getByRole('row').nth(index + 1)
}

test('the list loads the first page sorted by name and sends the contract query', async () => {
  const { table, screen } = await openContacts()

  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 25, total: 60 })))
    .toBeVisible()
  expect(table.getByRole('columnheader', { name: copy.contacts.columns.name }).element()).toHaveAttribute(
    'aria-sort',
    'ascending',
  )
  const request = contactRequests.at(-1)
  expect(request?.searchParams.get('page')).toBe('1')
  expect(request?.searchParams.get('per_page')).toBe('25')
  expect(request?.searchParams.get('sort')).toBe('name')
})

test('clicking a sortable header updates the URL and aria-sort', async () => {
  const { table, currentLocation } = await openContacts()
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)
  const name = table.getByRole('columnheader', { name: copy.contacts.columns.name })
  const email = table.getByRole('columnheader', { name: copy.contacts.columns.email })

  await name.getByRole('button').click()
  await expect.element(name).toHaveAttribute('aria-sort', 'descending')
  expect(currentLocation().search).toMatchObject({ sort: '-name' })
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Jiban Karki/)
  expect(contactRequests.at(-1)?.searchParams.get('sort')).toBe('-name')

  await email.getByRole('button').click()
  await expect.element(email).toHaveAttribute('aria-sort', 'ascending')
  await expect.element(name).toHaveAttribute('aria-sort', 'none')
  expect(currentLocation().search).toMatchObject({ sort: 'email' })

  await email.getByRole('button').click()
  await expect.element(email).toHaveAttribute('aria-sort', 'descending')
  await email.getByRole('button').click()
  // Third click: back to the default order, which leaves the URL clean.
  await expect.element(name).toHaveAttribute('aria-sort', 'ascending')
  expect(currentLocation().search).not.toHaveProperty('sort')
})

test('changing the page keeps the filters', async () => {
  const { table, screen, currentLocation } = await openContacts(`/acme/contacts?organization_id=${ACME},none`)
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 25, total: 40 })))
    .toBeVisible()

  await screen.getByRole('button', { name: copy.dataTable.nextPage }).click()

  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 26, to: 40, total: 40 })))
    .toBeVisible()
  expect(currentLocation().search).toMatchObject({ page: 2, organization_id: `${ACME},none` })
  const request = contactRequests.at(-1)
  expect(request?.searchParams.get('page')).toBe('2')
  expect(request?.searchParams.get('filter[organization_id]')).toBe(`${ACME},none`)
  await expect.element(dataRow(table, 0)).toBeVisible()
})

test('changing a filter goes back to page 1', async () => {
  const { screen, currentLocation } = await openContacts('/acme/contacts?page=2&sort=-created_at')
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 26, to: 50, total: 60 })))
    .toBeVisible()

  await screen.getByRole('combobox', { name: copy.contacts.list.tagFilter }).click()
  await screen.getByRole('option', { name: 'VIP' }).click()
  await userEvent.keyboard('{Escape}')

  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 15, total: 15 })))
    .toBeVisible()
  expect(currentLocation().search).toEqual({ sort: '-created_at', tag: 'vip' })
  expect(contactRequests.at(-1)?.searchParams.get('filter[tag]')).toBe('vip')
  expect(contactRequests.at(-1)?.searchParams.get('page')).toBe('1')
})

test('the search is debounced into the URL and resets the page', async () => {
  const { table, screen, currentLocation } = await openContacts('/acme/contacts?page=2')
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 26, to: 50, total: 60 })))
    .toBeVisible()

  await screen.getByRole('searchbox', { name: copy.contacts.list.searchLabel }).fill('karki')

  await expect.poll(() => currentLocation().search).toEqual({ search: 'karki' })
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 10, total: 10 })))
    .toBeVisible()
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Karki/)
  expect(contactRequests.at(-1)?.searchParams.get('search')).toBe('karki')
})

test('the back button restores the previous sort, page and filters', async () => {
  const { table, screen, currentLocation, router } = await openContacts('/acme/contacts?tag=vip')
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 15, total: 15 })))
    .toBeVisible()
  const name = table.getByRole('columnheader', { name: copy.contacts.columns.name })

  await name.getByRole('button').click()
  await expect.element(name).toHaveAttribute('aria-sort', 'descending')
  await screen.getByRole('button', { name: copy.filters.clear }).click()
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 25, total: 60 })))
    .toBeVisible()
  expect(currentLocation().search).toEqual({ sort: '-name' })

  router.history.back()
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 15, total: 15 })))
    .toBeVisible()
  expect(currentLocation().search).toEqual({ sort: '-name', tag: 'vip' })

  router.history.back()
  await expect.element(name).toHaveAttribute('aria-sort', 'ascending')
  expect(currentLocation().search).toEqual({ tag: 'vip' })
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)

  router.history.forward()
  await expect.element(name).toHaveAttribute('aria-sort', 'descending')
})

test('unrelated search params survive list changes', async () => {
  const { table, currentLocation } = await openContacts('/acme/contacts?panel=help')
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)

  await table.getByRole('columnheader', { name: copy.contacts.columns.email }).getByRole('button').click()

  await expect.poll(() => currentLocation().search).toEqual({ panel: 'help', sort: 'email' })
})

test('invalid URL values fall back to the defaults instead of failing', async () => {
  const { table, screen } = await openContacts('/acme/contacts?sort=password&per_page=7&page=zero&tag=')
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)
  await expect
    .element(screen.getByText(fill(copy.dataTable.range, { from: 1, to: 25, total: 60 })))
    .toBeVisible()
  const request = contactRequests.at(-1)
  expect(request?.searchParams.get('sort')).toBe('name')
  expect(request?.searchParams.get('per_page')).toBe('25')
  expect(request?.searchParams.has('filter[tag]')).toBe(false)
})

test('arrow keys, Home and End move focus between rows', async () => {
  const { table } = await openContacts()
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)
  const first = dataRow(table, 0)

  expect(first.element()).toHaveAttribute('tabindex', '0')
  expect(dataRow(table, 1).element()).toHaveAttribute('tabindex', '-1')
  ;(first.element() as HTMLElement).focus()

  await userEvent.keyboard('{ArrowDown}')
  await expect.element(dataRow(table, 1)).toHaveFocus()
  await userEvent.keyboard('j')
  await expect.element(dataRow(table, 2)).toHaveFocus()
  await userEvent.keyboard('{ArrowUp}')
  await expect.element(dataRow(table, 1)).toHaveFocus()
  expect(dataRow(table, 1).element()).toHaveAttribute('tabindex', '0')
  await userEvent.keyboard('{End}')
  await expect.element(dataRow(table, 24)).toHaveFocus()
  await userEvent.keyboard('{Home}')
  await expect.element(first).toHaveFocus()
  await userEvent.keyboard('{ArrowUp}')
  await expect.element(first).toHaveFocus()
})

test('x selects the focused row and the bulk bar offers its actions', async () => {
  const { table, screen } = await openContacts()
  await expect.element(dataRow(table, 0)).toMatchTextContent(/Aarav Adhikari/)
  ;(dataRow(table, 0).element() as HTMLElement).focus()

  await userEvent.keyboard('x')
  await userEvent.keyboard('{ArrowDown}')
  await userEvent.keyboard('x')

  const bar = screen.getByRole('region', { name: copy.dataTable.bulkActions })
  await expect.element(bar).toBeVisible()
  await expect.element(bar.getByText(fill(copy.dataTable.selectedCount, { count: 2 }))).toBeVisible()
  await expect.element(bar.getByRole('button', { name: copy.contacts.list.copyEmails })).toBeVisible()

  await bar.getByRole('button', { name: copy.dataTable.clearSelection }).click()
  await expect.element(bar).not.toBeInTheDocument()
})

test('hidden columns stay hidden after a reload', async () => {
  const first = await openContacts()
  await expect
    .element(first.table.getByRole('columnheader', { name: copy.contacts.columns.phone }))
    .toBeVisible()

  await first.screen.getByRole('button', { name: copy.dataTable.columns }).click()
  await first.screen.getByRole('menuitemcheckbox', { name: copy.contacts.columns.phone }).click()
  await userEvent.keyboard('{Escape}')

  await expect
    .element(first.table.getByRole('columnheader', { name: copy.contacts.columns.phone }))
    .not.toBeInTheDocument()
  expect(JSON.parse(localStorage.getItem(columnVisibilityKey('contacts')) ?? '{}')).toEqual({ phone: false })
  await first.screen.unmount()

  const reloaded = await openContacts()
  await expect.element(dataRow(reloaded.table, 0)).toMatchTextContent(/Aarav Adhikari/)
  await expect
    .element(reloaded.table.getByRole('columnheader', { name: copy.contacts.columns.email }))
    .toBeVisible()
  expect(reloaded.table.getByRole('columnheader', { name: copy.contacts.columns.phone }).query()).toBeNull()
})

test('an empty workspace shows the empty state', async () => {
  worker.use(contactsHandler([]))
  const { screen } = await openContacts()

  await expect
    .element(screen.getByRole('heading', { level: 2, name: copy.contacts.list.emptyTitle }))
    .toBeVisible()
  expect(screen.getByRole('navigation', { name: copy.dataTable.pagination }).query()).toBeNull()
})

test('a search without matches offers to clear the filters', async () => {
  const { screen, currentLocation } = await openContacts('/acme/contacts?search=zzz')

  await expect
    .element(screen.getByRole('heading', { level: 2, name: copy.contacts.list.noMatchesTitle }))
    .toBeVisible()
  await screen.getByRole('main').getByRole('button', { name: copy.filters.clear }).last().click()

  await expect.poll(() => currentLocation().search).toEqual({})
  await expect
    .element(screen.getByRole('searchbox', { name: copy.contacts.list.searchLabel }))
    .toHaveValue('')
})

test('a failed request shows the error state with the request id and retries', async () => {
  let calls = 0
  worker.use(
    http.get(apiUrl('/contacts'), () => {
      calls += 1
      return calls === 1
        ? problem(500, 'server_error', { title: 'Server error' }, '01JREQUEST0000000000000000')
        : HttpResponse.json({
            data: CONTACT_FIXTURES.slice(0, 1),
            links: { first: null, last: null, prev: null, next: null },
            meta: {
              current_page: 1,
              from: 1,
              last_page: 1,
              links: [],
              path: null,
              per_page: 25,
              to: 1,
              total: 1,
            },
          })
    }),
  )
  const { screen, table } = await openContacts()

  await expect.element(screen.getByRole('alert')).toBeVisible()
  await expect
    .element(screen.getByText(fill(copy.states.error.requestId, { id: '01JREQUEST0000000000000000' })))
    .toBeVisible()

  await screen.getByRole('button', { name: copy.states.error.retry }).click()
  await expect.element(dataRow(table, 0)).toMatchTextContent(new RegExp(CONTACT_FIXTURES[0]?.name ?? ''))
})

import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test } from 'vitest'
import { type Locator, userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import {
  AGENT_FIXTURES,
  CATEGORY_FIXTURES,
  DUPLICATE_SUGGESTED_FIXTURES,
  MOCK_ME_AGENT_ID,
  TEAM_FIXTURES,
  TICKET_FIXTURES,
  TICKET_SLA_FIXTURES,
} from '@/test/msw/tickets'
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

/** A signed-in Agent who may read the directory, so the assignee and team filters list names. */
const AGENT_SESSION = {
  permissions: ['tickets.view', 'tickets.create', 'agents.view'],
  agent_profile: {
    id: MOCK_ME_AGENT_ID,
    capacity: 10,
    availability: 'available' as const,
    active_ticket_count: 1,
  },
}

async function openTickets(
  path = '/acme/tickets',
  session: Parameters<typeof sessionFixture>[0] = { permissions: ['tickets.view', 'tickets.create'] },
) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture(session) })))
  const app = await renderApp(path)
  return { ...app, table: app.screen.getByRole('table', { name: copy.tickets.list.label }) }
}

test('a row carries what decides the next pick: state, priority, SLA, channel, requester and age', async () => {
  const { table } = await openTickets()
  const first = TICKET_FIXTURES[0]
  const row = table.getByRole('row').nth(1)

  await expect.element(row).toMatchTextContent(new RegExp(`#${first?.number}`))
  await expect
    .element(table.getByRole('columnheader', { name: copy.tickets.columns.priority }))
    .toHaveAttribute('aria-sort', 'descending')
  await expect.element(row).toMatchTextContent(/P1 Critical/)
  // The requester rides with the subject (M4-04), so the Contact column is off by default.
  await expect.element(row).toMatchTextContent(/Aarav Adhikari/)
  for (const column of [copy.tickets.columns.channel, copy.tickets.columns.sla, copy.tickets.columns.age]) {
    await expect.element(table.getByRole('columnheader', { name: column })).toBeVisible()
  }
  expect(table.getByRole('columnheader', { name: copy.tickets.columns.category }).query()).toBeNull()
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
  // The date filter's own trigger; the chip beside it (M4-04) carries the same words.
  await expect
    .element(screen.getByRole('button', { name: /Created.*2026/, exact: false }).first())
    .toMatchTextContent(/1 Sept? 2026 – 6 Sept? 2026/)
  await expect
    .element(screen.getByRole('list', { name: copy.filters.active }))
    .toMatchTextContent(/2026-09-01 – 2026-09-06/)

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
  // The conversation is the default view since M4-05; the timeline is one tab away.
  await screen.getByRole('tab', { name: copy.tickets.detail.timeline }).click()
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

/** Roadmap M2-11: the complete filter bar, the quick views and the SLA due sort. */
function rowsText(table: Locator): string[] {
  return table
    .getByRole('row')
    .elements()
    .slice(1)
    .map((row) => row.textContent ?? '')
}

async function pick(
  screen: Awaited<ReturnType<typeof renderApp>>['screen'],
  filter: string,
  ...options: string[]
) {
  await screen.getByRole('combobox', { name: filter }).click()
  for (const option of options) await screen.getByRole('option', { name: option, exact: true }).click()
  await userEvent.keyboard('{Escape}')
}

test('every new filter in a shared URL reaches the API as filter[…]', async () => {
  const acme = '019a0002-0000-7000-8000-000000000001'
  const team = TEAM_FIXTURES[0]?.id ?? ''
  const { screen } = await openTickets(
    `/acme/tickets?assignee_id=unassigned,me&team_id=none,${team}&tag=vip&organization_id=${acme}&sla_state=breached,warning&has_duplicate_suggestion=true`,
    AGENT_SESSION,
  )
  await expect.poll(() => requests.at(-1)?.searchParams.get('filter[assignee_id]')).toBe('unassigned,me')
  const request = requests.at(-1)
  expect(request?.searchParams.get('filter[team_id]')).toBe(`none,${team}`)
  expect(request?.searchParams.get('filter[tag]')).toBe('vip')
  expect(request?.searchParams.get('filter[organization_id]')).toBe(acme)
  expect(request?.searchParams.get('filter[sla_state]')).toBe('breached,warning')
  expect(request?.searchParams.get('filter[has_duplicate_suggestion]')).toBe('true')
  await expect
    .element(screen.getByRole('combobox', { name: `${copy.tickets.list.assigneeFilter}, 2 selected` }))
    .toBeVisible()
  await expect
    .element(screen.getByRole('combobox', { name: copy.tickets.list.duplicateFilter }))
    .toMatchTextContent(copy.tickets.list.duplicateSuggested)
})

test('assignee, team, tag, organisation, SLA and duplicate filters round-trip through the URL', async () => {
  const { screen, table, currentLocation, router } = await openTickets('/acme/tickets', AGENT_SESSION)
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  const agent = AGENT_FIXTURES[1]

  await pick(
    screen,
    copy.tickets.list.assigneeFilter,
    agent?.user.name ?? '',
    copy.tickets.list.unassignedOption,
  )
  await expect.poll(() => currentLocation().search).toEqual({ assignee_id: `${agent?.id},unassigned` })
  await expect
    .poll(() => requests.at(-1)?.searchParams.get('filter[assignee_id]'))
    .toBe(`${agent?.id},unassigned`)
  await expect.poll(() => rowsText(table).length).toBeGreaterThan(0)
  for (const row of rowsText(table)) expect(row).toMatch(new RegExp(`${agent?.user.name}|Unassigned`))

  await pick(screen, copy.tickets.list.teamFilter, copy.tickets.list.noTeamOption)
  await pick(screen, copy.tickets.list.tagFilter, 'VIP')
  await expect
    .poll(() => currentLocation().search)
    .toEqual({ assignee_id: `${agent?.id},unassigned`, team_id: 'none', tag: 'vip' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('filter[tag]')).toBe('vip')
  expect(requests.at(-1)?.searchParams.get('filter[team_id]')).toBe('none')

  await screen.getByRole('button', { name: copy.filters.clear }).click()
  await pick(screen, copy.tickets.list.organizationFilter, 'Acme Corporation')
  await pick(screen, copy.tickets.list.slaFilter, copy.tickets.list.slaStates.breached)
  await screen.getByRole('combobox', { name: copy.tickets.list.duplicateFilter }).click()
  await screen.getByRole('option', { name: copy.tickets.list.duplicateSuggested }).click()
  await expect
    .poll(() => currentLocation().search)
    .toEqual({
      organization_id: '019a0002-0000-7000-8000-000000000001',
      sla_state: 'breached',
      has_duplicate_suggestion: 'true',
    })
  const request = requests.at(-1)
  expect(request?.searchParams.get('filter[organization_id]')).toBe('019a0002-0000-7000-8000-000000000001')
  expect(request?.searchParams.get('filter[sla_state]')).toBe('breached')
  expect(request?.searchParams.get('filter[has_duplicate_suggestion]')).toBe('true')
  const expected = TICKET_FIXTURES.filter(
    (ticket) =>
      ticket.organization_id === '019a0002-0000-7000-8000-000000000001' &&
      TICKET_SLA_FIXTURES[ticket.id]?.state === 'breached' &&
      DUPLICATE_SUGGESTED_FIXTURES.includes(ticket.id),
  )
  expect(expected.length).toBeGreaterThan(0)
  await expect.poll(() => rowsText(table).length).toBe(expected.length)

  router.history.back()
  await expect.poll(() => currentLocation().search).not.toHaveProperty('has_duplicate_suggestion')
})

test('quick views are URL presets, and a hand-made change shows "Custom filters"', async () => {
  const { screen, table, currentLocation } = await openTickets('/acme/tickets', AGENT_SESSION)
  const views = screen.getByRole('group', { name: copy.tickets.quickViews.label })
  await expect
    .element(views.getByRole('button', { name: copy.tickets.quickViews.all }))
    .toHaveAttribute('aria-pressed', 'true')

  await views.getByRole('button', { name: copy.tickets.quickViews.mine }).click()
  await expect.poll(() => currentLocation().search).toEqual({ assignee_id: 'me', status: 'active' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('filter[assignee_id]')).toBe('me')
  expect(requests.at(-1)?.searchParams.get('filter[status]')).toBe('active')
  await expect
    .element(views.getByRole('button', { name: copy.tickets.quickViews.mine }))
    .toHaveAttribute('aria-pressed', 'true')
  const mine = TICKET_FIXTURES.filter(
    (ticket) =>
      ticket.assigned_agent_id === MOCK_ME_AGENT_ID && !['resolved', 'closed'].includes(ticket.status),
  )
  await expect.poll(() => rowsText(table).length).toBe(mine.length)

  await views.getByRole('button', { name: copy.tickets.quickViews.unassigned }).click()
  await expect.poll(() => currentLocation().search).toEqual({ assignee_id: 'unassigned', status: 'active' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('filter[assignee_id]')).toBe('unassigned')

  await views.getByRole('button', { name: copy.tickets.quickViews.breaching }).click()
  await expect
    .poll(() => currentLocation().search)
    .toEqual({ sla_state: 'warning,breached', sort: 'sla_due_at' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('sort')).toBe('sla_due_at')
  expect(requests.at(-1)?.searchParams.get('filter[sla_state]')).toBe('warning,breached')
  expect(requests.at(-1)?.searchParams.has('filter[assignee_id]')).toBe(false)

  await pick(screen, copy.tickets.list.priorityFilter, copy.tickets.priority.P1)
  await expect.element(screen.getByText(copy.tickets.quickViews.custom)).toBeVisible()
  for (const button of views.getByRole('button').elements())
    expect(button.getAttribute('aria-pressed')).toBe('false')

  await views.getByRole('button', { name: copy.tickets.quickViews.all }).click()
  await expect.poll(() => currentLocation().search).toEqual({})
  expect(screen.getByText(copy.tickets.quickViews.custom).query()).toBeNull()
})

test('"My tickets" is offered only to a user with an Agent profile', async () => {
  const { screen } = await openTickets()
  const views = screen.getByRole('group', { name: copy.tickets.quickViews.label })
  await expect.element(views.getByRole('button', { name: copy.tickets.quickViews.unassigned })).toBeVisible()
  expect(views.getByRole('button', { name: copy.tickets.quickViews.mine }).query()).toBeNull()
})

test('the SLA due column can be shown from the column menu and sorts by the resolution due time', async () => {
  const { screen, table, currentLocation } = await openTickets('/acme/tickets', AGENT_SESSION)
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  expect(table.getByRole('columnheader', { name: copy.tickets.columns.slaDue }).query()).toBeNull()

  await screen.getByRole('button', { name: copy.dataTable.columns }).click()
  await screen.getByRole('menuitemcheckbox', { name: copy.tickets.columns.slaDue }).click()
  await userEvent.keyboard('{Escape}')
  const header = table.getByRole('columnheader', { name: copy.tickets.columns.slaDue })
  await header.getByRole('button').click()

  await expect.element(header).toHaveAttribute('aria-sort', 'ascending')
  expect(currentLocation().search).toEqual({ sort: 'sla_due_at' })
  await expect.poll(() => requests.at(-1)?.searchParams.get('sort')).toBe('sla_due_at')
  const running = TICKET_FIXTURES.filter((ticket) => TICKET_SLA_FIXTURES[ticket.id]?.due_at)
    .map((ticket) => ({ number: ticket.number, due: TICKET_SLA_FIXTURES[ticket.id]?.due_at ?? '' }))
    .sort((a, b) => a.due.localeCompare(b.due))
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(new RegExp(`#${running[0]?.number}`))

  await header.getByRole('button').click()
  await expect.element(header).toHaveAttribute('aria-sort', 'descending')
  await expect
    .element(table.getByRole('row').nth(1))
    .toMatchTextContent(new RegExp(`#${running.at(-1)?.number}`))
})

test('a filter in force shows as a chip that removes it (M4-04)', async () => {
  const { screen, currentLocation } = await openTickets('/acme/tickets?status=open,pending&priority=P1')
  await expect.element(screen.getByRole('list', { name: copy.filters.active })).toBeVisible()

  const chips = screen.getByRole('list', { name: copy.filters.active })
  await expect.element(chips).toMatchTextContent(/Status:\s*Open, Pending/)
  await expect.element(chips).toMatchTextContent(/Priority:\s*P1 Critical/)

  await chips.getByRole('button', { name: new RegExp(`${copy.tickets.list.priorityFilter}`) }).click()

  await expect.poll(() => currentLocation().search).not.toHaveProperty('priority')
  await expect.poll(() => currentLocation().search).toHaveProperty('status', 'open,pending')
})

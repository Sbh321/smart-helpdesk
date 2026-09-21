import { delay, HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db, fixtureId } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { AGENT_FIXTURES, TEAM_FIXTURES } from '@/test/msw/tickets'
import { renderApp } from '@/test/render-app'

/** Roadmap M2-11: row selection and the bulk status change and assignment of the ticket list. */
const worker = setupMswWorker()
const text = copy.tickets.bulk
const bulkBodies: { path: string; body: { ticket_ids: string[] } & Record<string, unknown> }[] = []

beforeEach(() => {
  bulkBodies.length = 0
  worker.events.on('request:start', async ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname.startsWith('/v1/tickets/bulk/')) {
      bulkBodies.push({ path: url.pathname, body: await request.clone().json() })
    }
  })
})
afterEach(() => worker.events.removeAllListeners())

const ALL = [
  'tickets.view',
  'tickets.update',
  'tickets.assign',
  'tickets.resolve',
  'tickets.close',
  'agents.view',
]

async function openTickets(path: string, permissions = ALL) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp(path)
  const table = app.screen.getByRole('table', { name: copy.tickets.list.label })
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  return { ...app, table, bar: app.screen.getByRole('region', { name: copy.dataTable.bulkActions }) }
}

test('Change status on 50 rows reports every ticket, and "Select failed" selects the failures', async () => {
  const { screen, table, bar } = await openTickets('/acme/tickets?per_page=50')
  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  await expect.element(bar).toMatchTextContent(fill(copy.dataTable.selectedCount, { count: 50 }))

  await bar.getByRole('button', { name: text.changeStatus }).click()
  const dialog = screen.getByRole('dialog', { name: fill(text.statusTitle, { count: 50 }) })
  await dialog.getByRole('combobox', { name: text.statusLabel }).click()
  await screen.getByRole('option', { name: copy.tickets.status.resolved }).click()

  // Resolving needs a comment, as the single-ticket dialog asks.
  await dialog.getByRole('button', { name: fill(text.submit, { count: 50 }) }).click()
  await expect.element(dialog.getByText(text.commentRequired)).toBeVisible()
  expect(bulkBodies).toHaveLength(0)

  await dialog
    .getByRole('textbox', { name: copy.tickets.detail.resolutionComment })
    .fill('Fixed by the rollback.')
  await dialog.getByRole('button', { name: fill(text.submit, { count: 50 }) }).click()

  const result = screen.getByRole('dialog', { name: text.resultTitle })
  // Of the first 50 fixtures, 8 are resolved and 8 closed already: those cannot move to resolved.
  await expect.element(result).toMatchTextContent(fill(text.resultSummary, { succeeded: 34, total: 50 }))
  await expect
    .element(result.getByRole('heading', { name: fill(text.failuresTitle, { count: 16 }) }))
    .toBeVisible()
  await expect
    .element(result.getByRole('listitem').first())
    .toMatchTextContent(/^#1005 .+: A ticket cannot move from resolved to resolved\.$/)
  expect(result.getByRole('listitem').elements()).toHaveLength(16)
  expect(bulkBodies).toHaveLength(1)
  expect(bulkBodies[0]?.body).toMatchObject({ status: 'resolved', comment: 'Fixed by the rollback.' })
  expect(bulkBodies[0]?.body.ticket_ids).toHaveLength(50)
  expect(db.tickets.filter((ticket) => ticket.status === 'resolved')).toHaveLength(34 + 10)

  await result.getByRole('button', { name: text.selectFailed }).click()
  await expect.element(result).not.toBeInTheDocument()
  await expect.element(bar).toMatchTextContent(fill(copy.dataTable.selectedCount, { count: 16 }))
  const checked = table.getByRole('checkbox', { checked: true }).elements()
  expect(checked).toHaveLength(16)
})

test('bulk assign sends the chosen Agent, and auto-assign lists who could not be placed', async () => {
  // Two open tickets in categories whose required Skill no available Agent holds.
  const technical = db.categories.find((category) => category.name === 'Technical')
  const open = db.tickets.filter((ticket) => ticket.status === 'open')
  for (const ticket of open.slice(0, 2)) if (technical) ticket.category_id = technical.id
  const { screen, table, bar } = await openTickets('/acme/tickets?status=open')
  const agent = AGENT_FIXTURES[2]
  if (!agent) throw new Error('Missing Agent fixture')

  await table.getByRole('checkbox', { name: `Select #${open[2]?.number}` }).click()
  await table.getByRole('checkbox', { name: `Select #${open[3]?.number}` }).click()
  await bar.getByRole('button', { name: text.assign }).click()
  let dialog = screen.getByRole('dialog', { name: fill(text.assignTitle, { count: 2 }) })
  await dialog.getByRole('button', { name: fill(text.submit, { count: 2 }) }).click()
  await expect.element(dialog.getByText(text.targetRequired)).toBeVisible()
  await dialog.getByRole('combobox', { name: text.agentLabel }).click()
  await screen.getByRole('option', { name: agent.user.name }).click()
  await dialog.getByRole('button', { name: fill(text.submit, { count: 2 }) }).click()

  let result = screen.getByRole('dialog', { name: text.resultTitle })
  await expect.element(result).toMatchTextContent(fill(text.resultSummary, { succeeded: 2, total: 2 }))
  await expect.element(result.getByText(text.resultAllOk)).toBeVisible()
  expect(bulkBodies.at(-1)?.body).toEqual({ ticket_ids: [open[2]?.id, open[3]?.id], agent_id: agent.id })
  expect(db.tickets.find((ticket) => ticket.id === open[2]?.id)?.assigned_agent_id).toBe(agent.id)
  await result.getByRole('button', { name: text.done }).click()
  await expect.element(result).not.toBeInTheDocument()

  // The list refetched: the two tickets are assigned now and left the open filter.
  await expect.poll(() => table.getByRole('row').elements().length).toBe(open.length - 2 + 1)
  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  await bar.getByRole('button', { name: text.assign }).click()
  dialog = screen.getByRole('dialog', { name: fill(text.assignTitle, { count: open.length - 2 }) })
  await dialog.getByRole('combobox', { name: text.agentLabel }).click()
  await screen.getByRole('option', { name: text.autoOption }).click()
  await dialog.getByRole('button', { name: fill(text.submit, { count: open.length - 2 }) }).click()

  result = screen.getByRole('dialog', { name: text.resultTitle })
  await expect
    .element(result)
    .toMatchTextContent(fill(text.resultSummary, { succeeded: open.length - 4, total: open.length - 2 }))
  await expect.element(result.getByRole('listitem').first()).toMatchTextContent(copy.problems.noEligibleAgent)
  expect(result.getByRole('listitem').elements()).toHaveLength(2)
  expect(bulkBodies.at(-1)?.body).toMatchObject({ auto: true })
  expect(bulkBodies.at(-1)?.body).not.toHaveProperty('agent_id')
})

test('bulk assign can move tickets to a Team only', async () => {
  const { screen, table, bar } = await openTickets('/acme/tickets?status=open')
  const team = TEAM_FIXTURES[1]
  await table.getByRole('row').nth(1).getByRole('checkbox').click()
  await bar.getByRole('button', { name: text.assign }).click()
  const dialog = screen.getByRole('dialog', { name: fill(text.assignTitle, { count: 1 }) })
  await dialog.getByRole('combobox', { name: text.teamLabel }).click()
  await screen.getByRole('option', { name: team?.name ?? '' }).click()
  await dialog.getByRole('button', { name: fill(text.submit, { count: 1 }) }).click()
  await expect.element(screen.getByRole('dialog', { name: text.resultTitle })).toBeVisible()
  expect(bulkBodies.at(-1)?.body).toEqual({ ticket_ids: [expect.any(String)], team_id: team?.id })
})

test('a selection across pages of more than 100 tickets goes out in requests of at most 100', async () => {
  // 60 more tickets, so two pages of 100 hold 120.
  const extra = db.tickets.map((ticket, index) => ({
    ...ticket,
    id: fixtureId(5, 0x1000 + index),
    number: 2001 + index,
    status: 'in_progress' as const,
  }))
  db.tickets.push(...extra)
  const { screen, table, bar } = await openTickets('/acme/tickets?per_page=100')
  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  await expect.element(bar).toMatchTextContent(fill(copy.dataTable.selectedCount, { count: 100 }))
  await screen.getByRole('button', { name: copy.dataTable.nextPage }).click()
  await expect.element(table.getByRole('checkbox', { name: copy.dataTable.selectAll })).not.toBeChecked()
  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  await expect.element(bar).toMatchTextContent(fill(copy.dataTable.selectedCount, { count: 120 }))

  await bar.getByRole('button', { name: text.changeStatus }).click()
  const dialog = screen.getByRole('dialog', { name: fill(text.statusTitle, { count: 120 }) })
  await dialog.getByRole('combobox', { name: text.statusLabel }).click()
  await screen.getByRole('option', { name: copy.tickets.status.pending }).click()
  // Slow requests, so the progress between the two is visible; the handler then falls through to the mock.
  worker.use(
    http.post(apiUrl('/tickets/bulk/transition'), async () => {
      await delay(400)
    }),
  )
  await dialog.getByRole('button', { name: fill(text.submit, { count: 120 }) }).click()
  await expect
    .element(dialog.getByRole('progressbar', { name: text.progress }))
    .toHaveAttribute('aria-valuenow', '100')
  await expect.element(dialog.getByText(fill(text.running, { done: 100, total: 120 }))).toBeVisible()

  const result = screen.getByRole('dialog', { name: text.resultTitle })
  await expect.element(result).toMatchTextContent(/of 120 tickets changed/)
  expect(bulkBodies.map((request) => request.body.ticket_ids.length)).toEqual([100, 20])
  expect(new Set(bulkBodies.flatMap((request) => request.body.ticket_ids)).size).toBe(120)
})

test('without tickets.update or tickets.assign the list has no selection column', async () => {
  const { table } = await openTickets('/acme/tickets', ['tickets.view'])
  expect(table.getByRole('checkbox').query()).toBeNull()
})

test('tickets.assign alone offers Assign but not Change status', async () => {
  const { table, bar } = await openTickets('/acme/tickets', ['tickets.view', 'tickets.assign'])
  await table.getByRole('row').nth(1).getByRole('checkbox').click()
  await expect.element(bar.getByRole('button', { name: text.assign })).toBeVisible()
  expect(bar.getByRole('button', { name: text.changeStatus }).query()).toBeNull()
})

test('tickets.update alone offers Change status without the resolve and close targets', async () => {
  const { screen, table, bar } = await openTickets('/acme/tickets', ['tickets.view', 'tickets.update'])
  await table.getByRole('row').nth(1).getByRole('checkbox').click()
  expect(bar.getByRole('button', { name: text.assign }).query()).toBeNull()
  await bar.getByRole('button', { name: text.changeStatus }).click()
  const dialog = screen.getByRole('dialog', { name: fill(text.statusTitle, { count: 1 }) })
  await dialog.getByRole('combobox', { name: text.statusLabel }).click()
  await expect.element(screen.getByRole('option', { name: copy.tickets.status.pending })).toBeVisible()
  expect(screen.getByRole('option', { name: copy.tickets.status.resolved }).query()).toBeNull()
  expect(screen.getByRole('option', { name: copy.tickets.status.closed }).query()).toBeNull()
})

import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { afterEach, beforeEach, expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { FakeConnector, fakeEcho, realtimeConfig, restoreEcho, useFakeEcho } from '@/test/fake-echo'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'
import { realtimeChannels } from './channels'

/*
 * Live updates (M3-16) against a fake Echo connector: subscriptions per page, refetch on events, the
 * internal-note channel only with comments.internal, the connection indicator, and nothing at all
 * when the workspace or the deployment has realtime off.
 */
const worker = setupMswWorker()
const AGENT = ['tickets.view', 'tickets.update', 'comments.internal']
const text = copy.shell.realtime
const base = sessionFixture()
const tenantId = base.tenant.id
const requests: string[] = []

function signIn(permissions: string[], realtime = true) {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions,
          tenant: { ...base.tenant, features: { realtime, exports: true } },
        }),
      }),
    ),
  )
}

function fixtureTicket() {
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  return ticket
}

async function openComments(permissions = AGENT) {
  signIn(permissions)
  const ticket = fixtureTicket()
  const app = await renderApp(`/acme/tickets/${ticket.id}`, { config: realtimeConfig })
  await app.screen.getByRole('tab', { name: 'Comments' }).click()
  const panel = app.screen.getByRole('region', { name: 'Ticket comments' })
  await expect.element(panel.getByText('No comments yet.')).toBeVisible()
  return { ...app, ticket, panel }
}

function addStoredComment(ticketId: string, body: string, visibility: 'public' | 'internal') {
  const id = `01a0b0a3-9999-7000-8000-00000000000${visibility === 'public' ? 1 : 2}`
  db.comments[ticketId] = [
    ...(db.comments[ticketId] ?? []),
    {
      id,
      ticket_id: ticketId,
      visibility,
      author_type: 'user',
      author_id: null,
      body,
      attachments: [],
      created_at: '2026-09-21T09:00:00Z',
      updated_at: '2026-09-21T09:00:00Z',
    },
  ]
  return id
}

beforeEach(() => {
  useFakeEcho()
  requests.length = 0
  worker.events.on('request:start', ({ request }) => {
    requests.push(new URL(request.url).pathname)
  })
})
afterEach(() => {
  worker.events.removeAllListeners()
  restoreEcho()
})

test("another user's reply appears on the open ticket within a second", async () => {
  const { panel, ticket } = await openComments()
  const channel = realtimeChannels.ticket(tenantId, ticket.id)
  await expect.poll(() => fakeEcho().joined()).toContain(channel)

  const commentId = addStoredComment(ticket.id, 'Chen replied from the other browser.', 'public')
  fakeEcho().emit(channel, '.comment.added', {
    ticket_id: ticket.id,
    comment_id: commentId,
    visibility: 'public',
  })

  await expect
    .element(panel.getByText('Chen replied from the other browser.'), { timeout: 1_000 })
    .toBeVisible()
})

test('a reply arriving live leaves the draft, the mode and the focus of the agent who is typing (M4-05)', async () => {
  const { panel, ticket } = await openComments()
  const channel = realtimeChannels.ticket(tenantId, ticket.id)
  await expect.poll(() => fakeEcho().joined()).toContain(channel)

  await panel.getByRole('radio', { name: 'Internal note' }).click()
  const message = panel.getByRole('textbox', { name: 'Message' })
  await message.fill('Half-written note about the refund')
  ;(message.element() as HTMLTextAreaElement).focus()

  const commentId = addStoredComment(ticket.id, 'A reply from the other browser.', 'public')
  fakeEcho().emit(channel, '.comment.added', {
    ticket_id: ticket.id,
    comment_id: commentId,
    visibility: 'public',
  })
  await expect.element(panel.getByText('A reply from the other browser.'), { timeout: 1_000 }).toBeVisible()

  // Nothing the agent was working on moved.
  await expect.element(message).toHaveValue('Half-written note about the refund')
  await expect.element(panel.getByRole('radio', { name: 'Internal note' })).toBeChecked()
  expect(document.activeElement).toBe(message.element())
})

test('internal notes arrive on their own channel, joined only with comments.internal', async () => {
  const { panel, ticket } = await openComments()
  const internal = realtimeChannels.ticketInternal(tenantId, ticket.id)
  await expect.poll(() => fakeEcho().joined()).toContain(internal)

  const commentId = addStoredComment(ticket.id, 'Customer called twice.', 'internal')
  fakeEcho().emit(internal, '.comment.added', {
    ticket_id: ticket.id,
    comment_id: commentId,
    visibility: 'internal',
  })

  await expect.element(panel.getByText('Customer called twice.'), { timeout: 1_000 }).toBeVisible()
})

test('a member without comments.internal never joins the internal channel', async () => {
  const { ticket } = await openComments(['tickets.view', 'tickets.update'])
  await expect.poll(() => fakeEcho().joined()).toContain(realtimeChannels.ticket(tenantId, ticket.id))

  expect(fakeEcho().joined()).not.toContain(realtimeChannels.ticketInternal(tenantId, ticket.id))
})

test('the ticket list refetches when a ticket of the workspace changes', async () => {
  signIn(['tickets.view'])
  const app = await renderApp('/acme/tickets', { config: realtimeConfig })
  await expect.element(app.screen.getByRole('heading', { level: 1 })).toBeVisible()
  const channel = realtimeChannels.tickets(tenantId)
  await expect.poll(() => fakeEcho().joined()).toContain(channel)
  await expect.poll(() => requests.filter((path) => path === '/v1/tickets').length).toBeGreaterThan(0)
  const before = requests.filter((path) => path === '/v1/tickets').length

  // A bulk change sends one event per ticket; they coalesce into one refetch.
  for (const name of ['.ticket.created', '.ticket.status_changed', '.ticket.assigned']) {
    fakeEcho().emit(channel, name, { ticket_id: fixtureTicket().id })
  }

  await expect.poll(() => requests.filter((path) => path === '/v1/tickets').length).toBe(before + 1)
})

test('coming back after a drop refetches what the page shows, since missed events are not replayed', async () => {
  const { screen, panel, ticket } = await openComments()
  await expect.poll(() => fakeEcho().joined()).toContain(realtimeChannels.ticket(tenantId, ticket.id))

  fakeEcho().setStatus('failed')
  await expect.element(screen.getByRole('img', { name: text.offline })).toBeVisible()
  addStoredComment(ticket.id, 'Sent while the socket was down.', 'public')
  fakeEcho().setStatus('connected')

  await expect.element(panel.getByText('Sent while the socket was down.'), { timeout: 1_000 }).toBeVisible()
})

test('the bell refetches the unread count when a notification is created', async () => {
  signIn(['tickets.view'])
  const app = await renderApp('/acme', { config: realtimeConfig })
  await expect.element(app.screen.getByRole('heading', { level: 1 })).toBeVisible()
  const channel = realtimeChannels.user(tenantId, base.user.id)
  await expect.poll(() => fakeEcho().joined()).toContain(channel)
  await expect.poll(() => requests.filter((path) => path === '/v1/notifications').length).toBeGreaterThan(0)
  const before = requests.filter((path) => path === '/v1/notifications').length

  fakeEcho().emit(channel, '.notification.created', { kind: 'ticket_assigned' })

  await expect
    .poll(() => requests.filter((path) => path === '/v1/notifications').length)
    .toBeGreaterThan(before)
})

test('the indicator shows connected, offline and back, and announces only settled changes', async () => {
  signIn(['tickets.view'])
  const app = await renderApp('/acme', { config: realtimeConfig })
  const header = app.screen.getByRole('banner')
  await expect.element(header.getByRole('img', { name: text.connected })).toBeVisible()

  fakeEcho().setStatus('failed')
  await expect.element(header.getByRole('img', { name: text.offline })).toBeVisible()
  await expect
    .element(header.getByRole('status').filter({ hasText: text.offline }), { timeout: 3_000 })
    .toBeInTheDocument()

  fakeEcho().setStatus('connecting')
  await expect.element(header.getByRole('img', { name: text.reconnecting })).toBeVisible()
  fakeEcho().setStatus('connected')
  await expect.element(header.getByRole('img', { name: text.connected })).toBeVisible()
  await expect
    .element(header.getByRole('status').filter({ hasText: text.connected }), { timeout: 3_000 })
    .toBeInTheDocument()

  const results = await axe.run(document.body)
  expect(results.violations).toEqual([])
})

test('with the workspace flag off nothing connects and no indicator is shown', async () => {
  signIn(AGENT, false)
  const ticket = fixtureTicket()
  const app = await renderApp(`/acme/tickets/${ticket.id}`, { config: realtimeConfig })
  await expect
    .element(app.screen.getByRole('heading', { level: 1 }))
    .toHaveTextContent(`#${ticket.number} ${ticket.title}`)

  expect(FakeConnector.current).toBeNull()
  expect(app.screen.getByRole('img', { name: text.connected }).query()).toBeNull()
})

test('without Reverb in config.json nothing connects', async () => {
  signIn(AGENT)
  const app = await renderApp('/acme')
  await expect.element(app.screen.getByRole('heading', { level: 1 })).toBeVisible()

  expect(FakeConnector.current).toBeNull()
})

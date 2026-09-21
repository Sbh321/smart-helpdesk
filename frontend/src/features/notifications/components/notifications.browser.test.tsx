import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db, NOTIFICATION_FIXTURES } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'
import { NOTIFICATION_POLL_MS, notificationQueries } from '../api/notification-queries'

const worker = setupMswWorker()
const text = copy.notifications
const bell = copy.shell.notifications

function signedIn() {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'], unread_notifications: 2 }) }),
    ),
  )
}

const first = NOTIFICATION_FIXTURES[0]
if (!first) throw new Error('Missing notification fixture')

test('the bell shows the unread count and lists the latest notifications, unread first', async () => {
  signedIn()
  const { screen } = await renderApp('/acme')

  const button = screen.getByRole('button', { name: `${bell.label}: ${fill(bell.count, { count: 2 })}` })
  await expect.element(button).toBeVisible()
  await button.click()

  const list = screen.getByRole('list', { name: text.latest })
  await expect.element(list).toBeVisible()
  await expect.element(list.getByRole('listitem')).toHaveLength(3)
  await expect
    .element(list.getByRole('listitem').first())
    .toMatchTextContent(/Unread: A ticket was assigned to you/)
  await expect
    .element(list.getByRole('link', { name: `#${first.ticket_number} ${first.ticket_title}` }))
    .toBeVisible()
  await expect.element(screen.getByRole('link', { name: text.viewAll })).toBeVisible()
})

test('mark as read and mark all as read update the badge', async () => {
  signedIn()
  const { screen } = await renderApp('/acme')
  await screen.getByRole('button', { name: new RegExp(`^${bell.label}`) }).click()

  await screen.getByRole('button', { name: `${text.markRead}: ${text.kinds.ticket_assigned}` }).click()
  await expect.poll(() => db.notifications.filter((item) => item.read_at === null).length).toBe(1)
  await expect
    .element(screen.getByRole('button', { name: `${bell.label}: ${fill(bell.count, { count: 1 })}` }))
    .toBeVisible()

  await screen.getByRole('button', { name: text.markAllRead }).click()
  await expect.poll(() => db.notifications.every((item) => item.read_at !== null)).toBe(true)
  await expect.element(screen.getByRole('button', { name: bell.label, exact: true })).toBeVisible()
})

test('opening a notification goes to its ticket and marks it read', async () => {
  signedIn()
  const { screen, currentPath } = await renderApp('/acme')
  await screen.getByRole('button', { name: new RegExp(`^${bell.label}`) }).click()

  await screen.getByRole('link', { name: `#${first.ticket_number} ${first.ticket_title}` }).click()

  await expect.poll(currentPath).toBe(`/acme/tickets/${first.ticket_id}`)
  await expect.poll(() => db.notifications.find((item) => item.id === first.id)?.read_at).not.toBeNull()
})

test('a rising count is announced politely, and the first reading is not', async () => {
  signedIn()
  const { screen, queryClient } = await renderApp('/acme')
  const status = screen
    .getByRole('status')
    .filter({ hasText: /new notifications|^$/ })
    .first()
  await expect.element(screen.getByRole('button', { name: new RegExp(`^${bell.label}: 2`) })).toBeVisible()
  expect(document.body.textContent).not.toContain('new notifications')

  // The next poll, triggered directly: waiting out the 30 s interval with fake timers was flaky under load.
  db.notifications.unshift({ ...first, id: `${first.id.slice(0, -1)}f`, read_at: null })
  await queryClient.refetchQueries({ queryKey: ['notifications'] })

  await expect.element(screen.getByRole('button', { name: new RegExp(`^${bell.label}: 3`) })).toBeVisible()
  await expect.element(status).toMatchTextContent(fill(bell.announce, { count: 1 }))
})

test('the bell polls the unread count every 30 seconds', () => {
  expect(NOTIFICATION_POLL_MS).toBe(30_000)
  expect(notificationQueries.unreadCount('tenant').refetchInterval).toBe(NOTIFICATION_POLL_MS)
})

test('the notifications page filters unread and marks everything read', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/notifications')

  await expect.element(screen.getByRole('heading', { level: 1, name: text.title })).toBeVisible()
  const list = screen.getByRole('list', { name: text.title })
  await expect.element(list.getByRole('listitem')).toHaveLength(3)

  await screen.getByRole('switch', { name: text.unreadOnly }).click()
  await expect.element(list.getByRole('listitem')).toHaveLength(2)

  await screen.getByRole('button', { name: text.markAllRead }).click()
  await expect.element(screen.getByText(text.emptyUnread)).toBeVisible()
  expect(db.notifications.every((item) => item.read_at !== null)).toBe(true)
})

test('a failing list shows the error instead of an empty inbox', async () => {
  signedIn()
  worker.use(http.get(apiUrl('/notifications'), () => problem(500, 'internal_error')))
  const { screen } = await renderApp('/acme/notifications')

  await expect.element(screen.getByText(text.loadFailed)).toBeVisible()
  await expect.element(screen.getByText(text.empty)).not.toBeInTheDocument()
})

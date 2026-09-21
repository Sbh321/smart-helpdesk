import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { AGENT_FIXTURES, CATEGORY_FIXTURES, fixtureId, TEAM_FIXTURES, TICKET_FIXTURES } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { HISTORY_CONTACT_ID, HISTORY_ORGANIZATION_ID } from '@/test/msw/history'
import { renderApp } from '@/test/render-app'

/**
 * Roadmap M3-21: the Overview and History tabs of record pages. The as-of view shows each earlier
 * state and its differences from now, the timeline pages and names its actors, every entity's overview
 * renders its metrics, and the tabs follow the permissions of the API.
 */
const worker = setupMswWorker()
const text = copy.entity360

const MANAGER = ['tickets.view', 'contacts.view', 'contacts.manage', 'agents.view', 'history.view']

function signIn(permissions = MANAGER) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

async function openContactHistory() {
  const app = await renderApp(`/acme/contacts/${HISTORY_CONTACT_ID}`)
  await expect.element(app.screen.getByRole('heading', { level: 1, name: 'Aarav Adhikari' })).toBeVisible()
  await app.screen.getByRole('tab', { name: text.history }).click()
  return app
}

test('the history names each change of the organisation with its actor and old and new values', async () => {
  signIn()
  const { screen } = await openContactHistory()
  const timeline = screen.getByRole('list', { name: text.timeline.label })
  await expect.element(timeline).toBeVisible()
  const entries = timeline.getByRole('listitem').filter({ hasText: /Organisation/ })

  // Newest first: Asha moved it from Initech to Acme; the signed-in user from Globex to Initech.
  await expect.element(entries.nth(0)).toMatchTextContent(/Changed by Asha Rai/)
  await expect.element(entries.nth(0)).toMatchTextContent(/Organisation from Initech to Acme Corporation/)
  await expect.element(entries.nth(1)).toMatchTextContent(/Changed by You/)
  await expect.element(entries.nth(1)).toMatchTextContent(/Organisation from Globex to Initech/)
  // Creation by an API client, with the first organisation set.
  await expect.element(entries.nth(2)).toMatchTextContent(/Created by API client …e59dee00/)
  await expect.element(entries.nth(2)).toMatchTextContent(/Organisation set to Globex/)
  // Times are in the workspace zone (Asia/Kathmandu, +05:45).
  await expect.element(entries.nth(1).getByText('10 Sep 2026, 10:00:00')).toBeVisible()
  // The system's own change says so.
  await expect.element(timeline.getByText(/Changed by System/)).toBeVisible()
})

test('the as-of view shows each earlier state and its differences from now', async () => {
  signIn()
  const { screen } = await openContactHistory()
  const asOf = screen.getByRole('region', { name: text.asOf.title })
  const input = asOf.getByLabelText(text.asOf.label)

  // Between the two moves (12 Sep, Kathmandu time): the contact was at Initech.
  await input.fill('2026-09-12T10:00')
  await asOf.getByRole('button', { name: text.asOf.show }).click()
  const differences = asOf.getByRole('table', { name: text.asOf.differences })
  await expect.element(differences).toBeVisible()
  const organisation = differences.getByRole('row', { name: /Organisation/ })
  await expect.element(organisation).toMatchTextContent(/Organisation\s*Initech\s*Acme Corporation/)
  await expect.element(asOf.getByText(fill(text.asOf.versionsAfter, { count: 2 }))).toBeVisible()

  // "Just before" the first move: the contact was at Globex.
  const timeline = screen.getByRole('list', { name: text.timeline.label })
  const firstMove = timeline.getByRole('listitem').filter({ hasText: /from Globex to Initech/ })
  await firstMove.getByRole('button', { name: text.asOf.useChange }).click()
  await expect
    .element(differences.getByRole('row', { name: /Organisation/ }))
    .toMatchTextContent(/Organisation\s*Globex\s*Acme Corporation/)
  await expect.element(asOf.getByText(fill(text.asOf.versionsAfter, { count: 3 }))).toBeVisible()
  await expect.element(input).toHaveValue('2026-09-10T09:59:59')

  // Before the contact was created, it did not exist.
  await input.fill('2026-07-01T00:00')
  await asOf.getByRole('button', { name: text.asOf.show }).click()
  await expect.element(asOf.getByText(/This record did not exist on 1 Jul 2026, 00:00:00\./)).toBeVisible()

  // An empty value is refused in words, without a request.
  await input.fill('')
  await asOf.getByRole('button', { name: text.asOf.show }).click()
  await expect.element(asOf.getByText(text.asOf.invalid)).toBeVisible()
})

test('the timeline pages through older changes with the cursor', async () => {
  signIn()
  const { screen } = await renderApp(`/acme/organizations/${HISTORY_ORGANIZATION_ID}`)
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Acme Corporation' })).toBeVisible()
  await screen.getByRole('tab', { name: text.history }).click()
  const timeline = screen.getByRole('list', { name: text.timeline.label })
  await expect.poll(() => timeline.getByRole('listitem').all().length).toBe(50)
  await screen.getByRole('button', { name: text.timeline.loadOlder }).click()
  await expect.poll(() => timeline.getByRole('listitem').all().length).toBe(60)
  await expect.element(screen.getByRole('button', { name: text.timeline.loadOlder })).not.toBeInTheDocument()
  // The oldest entry is the creation.
  await expect.element(timeline.getByRole('listitem').last()).toMatchTextContent(/Created by You/)
  await expect.element(timeline.getByRole('listitem').last()).toMatchTextContent(/Tier set to Standard/)
})

test.each([
  ['a contact', `/acme/contacts/${HISTORY_CONTACT_ID}`, true, 'Tickets', '12'],
  ['an organisation', `/acme/organizations/${HISTORY_ORGANIZATION_ID}`, true, 'Tier', 'Enterprise'],
  ['a ticket', `/acme/tickets/${TICKET_FIXTURES[0]?.id}`, true, 'First response (business time)', '25m'],
  ['an agent', `/acme/agents/${AGENT_FIXTURES[0]?.id}`, false, 'Capacity', '10'],
  ['a team', `/acme/teams/${TEAM_FIXTURES[0]?.id}`, false, 'Resolved (30 days)', '15'],
  ['a category', `/acme/categories/${CATEGORY_FIXTURES[0]?.id}`, false, 'SLA compliance', '91.7%'],
])('the overview of %s renders its key figures', async (_kind, path, hasDetails, label, value) => {
  signIn()
  const { screen } = await renderApp(path)
  if (hasDetails) {
    await screen.getByRole('tab', { name: text.overview }).click()
  }
  const tile = screen.getByRole('region', { name: label, exact: true })
  await expect.element(tile).toBeVisible()
  await expect.element(tile).toMatchTextContent(new RegExp(value.replace('.', '\\.')))
})

test('the ticket overview draws the lifecycle trace from its intervals', async () => {
  signIn()
  const ticket = TICKET_FIXTURES[0]
  const { screen } = await renderApp(`/acme/tickets/${ticket?.id}`)
  await screen.getByRole('tab', { name: text.overview }).click()
  const trace = screen.getByRole('list', { name: text.lifecycle.chartLabel })
  await expect.element(trace).toBeVisible()
  const intervals = trace.getByRole('listitem')
  await expect.poll(() => intervals.all().length).toBe(3)
  await expect.element(intervals.nth(0)).toMatchTextContent(/Open.*1h/)
  await expect.element(intervals.nth(1)).toMatchTextContent(/Assigned.*Asha Rai.*2h/)
  await expect.element(intervals.nth(2)).toMatchTextContent(/In progress.*21h.*Still running/)
  // The table alternative carries the same intervals.
  const table = screen.getByRole('table', { name: text.lifecycle.tableLabel })
  await expect.poll(() => table.getByRole('row').all().length).toBe(4)
})

test('record pages link from the settings lists', async () => {
  signIn([...MANAGER, 'agents.manage'])
  const { screen, currentPath } = await renderApp('/acme/settings/teams')
  await screen.getByRole('link', { name: 'Technical' }).click()
  await expect.poll(currentPath).toBe(`/acme/teams/${TEAM_FIXTURES[1]?.id}`)
  await expect.element(screen.getByRole('heading', { level: 1, name: 'Technical' })).toBeVisible()
})

test('without history.view the History tab is not offered, and agents see ticket history only', async () => {
  const agent = ['tickets.view', 'contacts.view', 'comments.internal']
  signIn(agent)
  const { screen, router } = await renderApp(`/acme/contacts/${HISTORY_CONTACT_ID}`)
  await expect.element(screen.getByRole('tab', { name: text.overview })).toBeVisible()
  await expect.element(screen.getByRole('tab', { name: text.history })).not.toBeInTheDocument()

  await router.navigate({
    to: '/$workspace/tickets/$ticketId',
    params: { workspace: 'acme', ticketId: TICKET_FIXTURES[0]?.id ?? '' },
  })
  await screen.getByRole('tab', { name: text.history }).click()
  await expect.element(screen.getByRole('list', { name: text.timeline.label })).toBeVisible()
})

test('a 403 from the history API is explained instead of failing', async () => {
  signIn()
  worker.use(
    http.get(apiUrl('/history/{type}/{id}'), () => problem(403, 'forbidden', { title: 'Forbidden' })),
  )
  const { screen } = await openContactHistory()
  await expect.element(screen.getByText(text.timeline.forbidden)).toBeVisible()
})

test('an unknown record page says it does not exist', async () => {
  signIn()
  const { screen } = await renderApp(`/acme/teams/${fixtureId(9, 99)}`)
  await expect.element(screen.getByRole('heading', { name: copy.states.notFound.title })).toBeVisible()
})

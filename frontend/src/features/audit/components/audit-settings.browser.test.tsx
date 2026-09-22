import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { type Locator, userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { AUDIT_ASHA_ID, auditDb } from '@/test/msw/audit'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { HISTORY_CONTACT_ID } from '@/test/msw/history'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.audit
const ADMIN = ['tickets.view', 'settings.manage', 'users.manage', 'roles.manage', 'audit.view']

async function openAudit(path = '/acme/settings/audit', permissions = ADMIN) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp(path)
  const table = app.screen.getByRole('table', { name: text.tableLabel })
  return { ...app, table }
}

function row(table: Locator, action: string) {
  return table.getByRole('row').filter({ hasText: action })
}

function lastRequest(): URL | undefined {
  return auditDb.requests.at(-1)
}

test('lists entries newest first with readable actors, actions, records and workspace times', async () => {
  const { table } = await openAudit()
  const roles = row(table, 'user.role_changed')
  await expect.element(roles.getByText(text.actions['user.role_changed'] ?? '')).toBeVisible()
  await expect.element(roles.getByText('Bikram Shah')).toBeVisible()
  await expect.element(roles.getByText('Asha Rai')).toBeVisible()
  // 09:00 UTC is 14:45 in the workspace time zone (Asia/Kathmandu).
  await expect.element(roles.getByText('20 Sep 2026, 14:45:00')).toBeVisible()

  const own = row(table, 'role.permissions_changed')
  await expect.element(own.getByText(text.actors.you ?? '')).toBeVisible()
  await expect.element(row(table, 'webhook.disabled').getByText(text.actors.system ?? '')).toBeVisible()
  await expect.element(row(table, 'api_client.created').getByText('Monitoring')).toBeVisible()
  // A record without a name shows its type and a short id.
  await expect.element(row(table, 'api_client.created').getByText(/API client …/)).toBeVisible()
  // Newest first: the first body row is the latest entry.
  await expect.element(table.getByRole('row').nth(1).getByText('role.permissions_changed')).toBeVisible()
})

test('expands the recorded changes with old and new values', async () => {
  const { table } = await openAudit()
  const roles = row(table, 'user.role_changed')
  await roles.getByText(text.changes.showOne).click()
  await expect.element(roles.getByText('Roles', { exact: true })).toBeVisible()
  await expect.element(roles.getByRole('deletion')).toHaveTextContent('agent')
  await expect.element(roles.getByRole('insertion')).toHaveTextContent('agent, billing-lead')

  const override = row(table, 'ticket.priority_overridden')
  await override.getByText(fill(text.changes.show, { count: 2 })).click()
  await expect.element(override.getByRole('deletion')).toHaveTextContent('P3')
  await expect.element(override.getByRole('insertion')).toHaveTextContent('P1')
  await expect.element(override.getByText('VIP outage')).toBeVisible()
})

test('keeps the filters in the URL and sends them to the API', async () => {
  const { screen, table, currentLocation } = await openAudit('/acme/settings/audit?action=user.role_changed')
  await expect.element(row(table, 'user.role_changed')).toBeVisible()
  await expect.element(row(table, 'webhook.disabled')).not.toBeInTheDocument()
  expect(lastRequest()?.searchParams.get('filter[action]')).toBe('user.role_changed')

  await screen.getByRole('button', { name: copy.filters.clear }).click()
  await expect.element(row(table, 'webhook.disabled')).toBeVisible()

  await screen.getByRole('combobox', { name: text.filters.actorType }).click()
  await screen.getByRole('option', { name: text.actorTypes.system ?? '' }).click()
  await userEvent.keyboard('{Escape}')
  await expect.poll(() => currentLocation().search).toMatchObject({ actor_type: 'system' })
  await expect.element(row(table, 'user.role_changed')).not.toBeInTheDocument()
  await expect.element(row(table, 'webhook.disabled')).toBeVisible()
  expect(lastRequest()?.searchParams.get('filter[actor_type]')).toBe('system')
  expect(lastRequest()?.searchParams.has('page')).toBe(false)

  await screen.getByRole('combobox', { name: text.filters.subjectType }).click()
  await screen.getByRole('option', { name: text.subjectTypes.webhook_subscription ?? '' }).click()
  await expect
    .poll(() => currentLocation().search)
    .toMatchObject({
      actor_type: 'system',
      subject_type: 'webhook_subscription',
    })
})

test('shows the entries of one record from a link and lets the viewer drop that filter', async () => {
  const { screen, table, currentLocation } = await openAudit(
    `/acme/settings/audit?subject_type=user&subject_id=${AUDIT_ASHA_ID}`,
  )
  await expect.element(row(table, 'user.role_changed')).toBeVisible()
  await expect.element(row(table, 'user.logged_in').first()).not.toBeInTheDocument()
  await expect
    .element(screen.getByText(fill(text.filters.oneRecord, { id: `…${AUDIT_ASHA_ID.slice(-8)}` })))
    .toBeVisible()
  await screen.getByRole('button', { name: text.filters.clearRecord }).click()
  await expect.poll(() => currentLocation().search).not.toHaveProperty('subject_id')
})

test('loads older entries from the cursor', async () => {
  const { screen, table } = await openAudit()
  await expect.element(row(table, 'user.logged_in').first()).toBeVisible()
  // One header row and a first page of 25.
  await expect.poll(() => table.getByRole('row').all().length).toBe(26)
  await screen.getByRole('button', { name: text.loadOlder }).click()
  await expect.poll(() => table.getByRole('row').all().length).toBe(36)
  await expect.element(screen.getByRole('button', { name: text.loadOlder })).not.toBeInTheDocument()
  expect(lastRequest()?.searchParams.get('cursor')).toBe('25')
})

test('someone without audit.view has no Audit nav entry and a forbidden page', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'agents.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/audit')
  const nav = screen.getByRole('navigation', { name: copy.settings.sections })
  await expect.element(nav.getByRole('link', { name: copy.settings.teams })).toBeVisible()
  await expect.element(nav.getByRole('link', { name: text.nav })).not.toBeInTheDocument()
  await expect.element(screen.getByRole('table', { name: text.tableLabel })).not.toBeInTheDocument()
  expect(auditDb.requests).toHaveLength(0)
})

test('the audit page with expanded changes has no serious or critical axe violations', async () => {
  const { screen, table } = await openAudit()
  await row(table, 'user.role_changed').getByText(text.changes.showOne).click()
  await expect.element(row(table, 'user.role_changed').getByRole('insertion')).toBeVisible()
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
  const results = await axe.run(screen.container, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
  })
  const blocking = results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
  expect(blocking).toEqual([])
})

test('an audit entry about a record joins its History tab for someone with audit.view', async () => {
  auditDb.entries.push({
    ...(auditDb.entries[0] as (typeof auditDb.entries)[number]),
    id: '019a0aaa-0000-7000-8000-00000000c0de',
    action: 'contact.archived',
    actor_id: '019a0aaa-0000-7000-8000-0000000000aa',
    actor_name: 'Bikram Shah',
    subject_type: 'contact',
    subject_id: HISTORY_CONTACT_ID,
    subject_name: 'Aarav Adhikari',
    changes: { archived_at: { old: null, new: '2026-09-19T10:00:00Z' } },
    created_at: '2026-09-19T10:00:00.000000Z',
  })
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['tickets.view', 'contacts.view', 'history.view', 'audit.view'],
        }),
      }),
    ),
  )
  const { screen } = await renderApp(`/acme/contacts/${HISTORY_CONTACT_ID}`)
  await screen.getByRole('tab', { name: copy.entity360.history }).click()
  const timeline = screen.getByRole('list', { name: copy.entity360.timeline.label })
  const entry = timeline
    .getByRole('listitem')
    .filter({ hasText: fill(text.historyEntry, { action: text.actions['contact.archived'] ?? '' }) })
  await expect.element(entry).toMatchTextContent(/by Bikram Shah/)
  await expect.element(entry).toMatchTextContent(/Archived from Empty to 19 Sep 2026, 15:45:00/)
  const request = auditDb.requests.find((url) => url.searchParams.get('filter[subject_type]') === 'contact')
  expect(request?.searchParams.get('filter[subject_id]')).toBe(HISTORY_CONTACT_ID)
})

import axe, { type Result } from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { AGENT_FIXTURES } from './msw/agents'
import { setupMswWorker } from './msw/browser'
import { db } from './msw/data'
import { apiUrl, sessionFixture } from './msw/handlers'
import { renderApp } from './render-app'

/**
 * Automated accessibility checks (docs/06-design-system/accessibility.md §Testing). axe-core runs the
 * WCAG 2.2 AA rule sets against the rendered tree; `serious` and `critical` findings fail the test, which
 * is the same bar the E2E scan will use.
 */
const worker = setupMswWorker()

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

function blocking(violations: Result[]): string[] {
  return violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map(
      (violation) =>
        `${violation.id} (${violation.impact}): ${violation.nodes
          .map((node) => `${node.html} — ${node.failureSummary ?? ''}`)
          .join(' | ')}`,
    )
}

/**
 * Enter animations fade and scale, and a half-faded element makes axe measure a blended colour. Waiting
 * for the animations to finish keeps the contrast rule measuring the colours a user actually sees.
 */
async function settle(): Promise<void> {
  // The whole document: dialogs and menus open in a portal outside the rendered container.
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
}

async function scan(container: HTMLElement): Promise<string[]> {
  await settle()
  const results = await axe.run(container, { runOnly: { type: 'tag', values: WCAG_TAGS } })
  return blocking(results.violations)
}

test('the sign-in page has no serious or critical axe violations', async () => {
  const { screen } = await renderApp('/acme/login')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.auth.login.heading })).toBeVisible()

  expect(await scan(screen.container)).toEqual([])
})

test('the application shell has no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'contacts.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()

  expect(await scan(screen.container)).toEqual([])
})

test('the command palette dialog is reachable from the keyboard and stays accessible', async () => {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture() })))
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.dashboard.title })).toBeVisible()

  await screen.getByRole('button', { name: copy.shell.commandPalette.open }).click()
  const dialog = screen.getByRole('dialog')
  await expect.element(dialog).toBeVisible()
  await expect.element(dialog.getByText(copy.shell.commandPalette.title)).toBeVisible()

  expect(await scan(document.body)).toEqual([])
})

test('the contact list, its filter popup and its column menu have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['contacts.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/contacts?sort=-created_at')
  const table = screen.getByRole('table', { name: copy.contacts.list.label })
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()

  expect(await scan(screen.container)).toEqual([])

  await screen.getByRole('combobox', { name: copy.contacts.list.organizationFilter }).click()
  await expect.element(screen.getByRole('option', { name: 'Acme Corporation' })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
  await userEvent.keyboard('{Escape}')

  await screen.getByRole('button', { name: copy.dataTable.columns }).click()
  await expect.element(screen.getByRole('menu')).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

test('the ticket list and the new-ticket dialog have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'tickets.create'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  const table = screen.getByRole('table', { name: copy.tickets.list.label })
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()

  expect(await scan(screen.container)).toEqual([])

  await screen.getByRole('button', { name: copy.tickets.create.open }).click()
  const dialog = screen.getByRole('dialog', { name: copy.tickets.create.title })
  await expect.element(dialog).toBeVisible()
  await dialog.getByRole('button', { name: copy.tickets.create.submit }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: copy.tickets.create.titleLabel }))
    .toHaveAttribute('aria-invalid', 'true')

  expect(await scan(document.body)).toEqual([])
})

test('the ticket list with a selection, the bulk dialog and its results have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['tickets.view', 'tickets.update', 'tickets.assign', 'tickets.close', 'agents.view'],
        }),
      }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  const table = screen.getByRole('table', { name: copy.tickets.list.label })
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  await table.getByRole('checkbox', { name: copy.dataTable.selectAll }).click()
  const bar = screen.getByRole('region', { name: copy.dataTable.bulkActions })
  await expect.element(bar).toBeVisible()
  expect(await scan(screen.container)).toEqual([])

  await bar.getByRole('button', { name: copy.tickets.bulk.changeStatus }).click()
  const dialog = screen.getByRole('dialog', { name: fill(copy.tickets.bulk.statusTitle, { count: 25 }) })
  await dialog.getByRole('button', { name: fill(copy.tickets.bulk.submit, { count: 25 }) }).click()
  await expect.element(dialog.getByText(copy.tickets.bulk.statusRequired)).toBeVisible()
  expect(await scan(document.body)).toEqual([])

  await dialog.getByRole('combobox', { name: copy.tickets.bulk.statusLabel }).click()
  await screen.getByRole('option', { name: copy.tickets.status.closed }).click()
  await dialog.getByRole('button', { name: fill(copy.tickets.bulk.submit, { count: 25 }) }).click()
  const result = screen.getByRole('dialog', { name: copy.tickets.bulk.resultTitle })
  await expect.element(result.getByRole('button', { name: copy.tickets.bulk.selectFailed })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

test('the contact form and the organisation form have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['contacts.view', 'contacts.manage'] }) }),
    ),
  )
  const contact = await renderApp('/acme/contacts/new')
  await expect
    .element(contact.screen.getByRole('heading', { level: 1, name: copy.contacts.newTitle }))
    .toBeVisible()
  await contact.screen.getByRole('button', { name: copy.contacts.form.create }).click()
  await expect
    .element(contact.screen.getByRole('textbox', { name: copy.contacts.form.name }))
    .toHaveAttribute('aria-invalid', 'true')
  expect(await scan(contact.screen.container)).toEqual([])
  await contact.screen.unmount()

  const organization = await renderApp('/acme/organizations/new')
  await expect
    .element(organization.screen.getByRole('heading', { level: 1, name: copy.organizations.newTitle }))
    .toBeVisible()
  expect(await scan(organization.screen.container)).toEqual([])
})

test('the directory Settings forms have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: [
            'tickets.view',
            'settings.manage',
            'agents.view',
            'agents.manage',
            'teams.manage',
            'shifts.manage',
          ],
        }),
      }),
    ),
  )

  for (const [path, action] of [
    ['skills', 'Add skill'],
    ['teams', 'Add team'],
    ['categories', 'Add category'],
    ['agents', 'Add agent'],
  ]) {
    const app = await renderApp(`/acme/settings/${path}`)
    await app.screen.getByRole('button', { name: action }).click()
    expect(await scan(app.screen.container)).toEqual([])
    await app.screen.unmount()
  }

  const shifts = await renderApp('/acme/settings/shifts')
  const firstAgent = AGENT_FIXTURES[0]
  if (!firstAgent) throw new Error('Missing Agent fixture')
  await shifts.screen.getByRole('combobox', { name: copy.settings.selectAgent }).click()
  await shifts.screen.getByRole('option', { name: firstAgent.user.name }).click()
  await expect
    .element(shifts.screen.getByRole('region', { name: copy.settings.weeklyTemplate }))
    .toBeVisible()
  expect(await scan(shifts.screen.container)).toEqual([])
})

test('the SLA, calendar and priority Settings forms have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['tickets.view', 'settings.manage', 'sla.manage', 'calendars.manage'],
        }),
      }),
    ),
  )

  const sla = await renderApp('/acme/settings/sla')
  await sla.screen.getByRole('button', { name: copy.sla.addPolicy }).click()
  await sla.screen.getByRole('button', { name: copy.sla.savePolicy }).click()
  await expect.element(sla.screen.getByText(copy.sla.validation.nameRequired)).toBeVisible()
  expect(await scan(sla.screen.container)).toEqual([])
  await sla.screen.unmount()

  const calendars = await renderApp('/acme/settings/calendars')
  await calendars.screen.getByRole('button', { name: copy.sla.addCalendar }).click()
  expect(await scan(calendars.screen.container)).toEqual([])
  await calendars.screen.getByRole('button', { name: 'Remove Dashain', exact: true }).click()
  await expect.element(calendars.screen.getByRole('alertdialog')).toBeVisible()
  expect(await scan(document.body)).toEqual([])
  await calendars.screen.unmount()

  const priority = await renderApp('/acme/settings/priority')
  await priority.screen.getByRole('button', { name: copy.priority.preview }).click()
  await expect
    .element(priority.screen.getByRole('table', { name: copy.priority.previewCaption }))
    .toBeVisible()
  expect(await scan(priority.screen.container)).toEqual([])
})

test('the workspace Settings pages have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'settings.manage'] }) }),
    ),
  )

  for (const path of ['general', 'branding', 'automation', 'tickets']) {
    const app = await renderApp(`/acme/settings/${path}`)
    await expect.element(app.screen.getByRole('heading', { level: 2 }).first()).toBeVisible()
    await expect
      .element(app.screen.getByRole('button', { name: copy.workspaceSettings.save }).first())
      .toBeVisible()
    expect(await scan(app.screen.container), path).toEqual([])
    await app.screen.unmount()
  }
})

test('the notification bell, its popover and the notifications page have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'], unread_notifications: 2 }) }),
    ),
  )

  const shell = await renderApp('/acme')
  await shell.screen.getByRole('button', { name: /^Notifications/ }).click()
  await expect.element(shell.screen.getByRole('list', { name: copy.notifications.latest })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
  await shell.screen.unmount()

  const page = await renderApp('/acme/notifications')
  await expect.element(page.screen.getByRole('list', { name: copy.notifications.title })).toBeVisible()
  expect(await scan(page.screen.container)).toEqual([])
})

test('the Media library, its folder tree and its dialogs have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({ permissions: ['media.view', 'media.manage', 'media.upload'] }),
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/media')
  await expect.element(screen.getByRole('table', { name: copy.media.tableLabel })).toBeVisible()
  await screen.getByRole('treeitem', { name: 'Brand' }).click()
  expect(await scan(screen.container)).toEqual([])

  await screen.getByRole('button', { name: copy.media.deleteFolder }).click()
  await expect.element(screen.getByRole('alertdialog')).toBeVisible()
  expect(await scan(document.body)).toEqual([])
  await screen.getByRole('alertdialog').getByRole('button', { name: copy.confirm.cancel }).click()

  await screen.getByRole('treeitem', { name: copy.media.allFolders }).click()
  await screen.getByRole('button', { name: 'Edit screenshot-27.png' }).click()
  await expect.element(screen.getByRole('dialog', { name: copy.media.editTitle })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

test('the ticket tabs and the assignment dialog have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: [
            'tickets.view',
            'tickets.update',
            'tickets.assign',
            'comments.internal',
            'agents.view',
            'media.view',
            'media.upload',
          ],
        }),
      }),
    ),
  )
  const ticket = db.tickets[0]
  if (!ticket) throw new Error('Missing ticket fixture')
  const { screen } = await renderApp(`/acme/tickets/${ticket.id}`)
  for (const tab of [
    copy.tickets.detail.comments,
    copy.tickets.detail.attachments,
    copy.tickets.detail.duplicates,
  ]) {
    await screen.getByRole('tab', { name: tab }).click()
    await expect.element(screen.getByRole('tabpanel')).toBeVisible()
    expect(await scan(screen.container)).toEqual([])
  }
  await screen.getByRole('button', { name: copy.assignment.title, exact: true }).click()
  await expect.element(screen.getByRole('table', { name: copy.assignment.rankingCaption })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

test('the Users and Roles Settings pages and their dialogs have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: sessionFixture({
          permissions: ['tickets.view', 'settings.manage', 'users.manage', 'roles.manage'],
        }),
      }),
    ),
  )

  const users = await renderApp('/acme/settings/users')
  await expect
    .element(users.screen.getByRole('table', { name: copy.users.tableLabel }).getByText('Asha Rai'))
    .toBeVisible()
  expect(await scan(users.screen.container)).toEqual([])
  await users.screen.getByRole('button', { name: copy.users.invite }).click()
  const invite = users.screen.getByRole('dialog', { name: copy.users.inviteTitle })
  await invite.getByRole('button', { name: copy.users.sendInvitation }).click()
  await expect
    .element(invite.getByRole('textbox', { name: copy.users.name }))
    .toHaveAttribute('aria-invalid', 'true')
  expect(await scan(document.body)).toEqual([])
  await users.screen.unmount()

  const roles = await renderApp('/acme/settings/roles')
  await expect.element(roles.screen.getByRole('heading', { level: 4, name: 'Owner' })).toBeVisible()
  expect(await scan(roles.screen.container)).toEqual([])
  await roles.screen.getByRole('button', { name: 'Edit billing-lead' }).click()
  const editor = roles.screen.getByRole('dialog', { name: 'Edit billing-lead' })
  await expect.element(editor.getByRole('checkbox', { name: 'View tickets' })).toBeChecked()
  expect(await scan(document.body)).toEqual([])
})

test('the API clients page, its create dialog and the one-time secret have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'integrations.manage'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/api-clients')
  await expect
    .element(screen.getByRole('table', { name: copy.apiClients.listLabel }).getByText('Monitoring'))
    .toBeVisible()
  expect(await scan(screen.container)).toEqual([])

  await screen.getByRole('button', { name: copy.apiClients.create }).click()
  const dialog = screen.getByRole('dialog', { name: copy.apiClients.createTitle })
  await dialog.getByRole('button', { name: copy.apiClients.save }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: copy.apiClients.name }))
    .toHaveAttribute('aria-invalid', 'true')
  expect(await scan(document.body)).toEqual([])

  await dialog.getByRole('textbox', { name: copy.apiClients.name }).fill('Nagios')
  await dialog.getByRole('checkbox', { name: /^tickets:read/ }).click()
  await dialog.getByRole('button', { name: copy.apiClients.save }).click()
  const secret = screen.getByRole('dialog', { name: 'Nagios' })
  await expect.element(secret.getByRole('textbox', { name: copy.apiClients.clientSecret })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

test('the webhooks page, its delivery log, create dialog and one-time secret have no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'integrations.manage'] }) }),
    ),
  )
  const text = copy.webhooks
  const { screen } = await renderApp('/acme/settings/webhooks')
  await expect
    .element(screen.getByRole('table', { name: text.listLabel }).getByText('CRM sync'))
    .toBeVisible()
  await screen.getByRole('button', { name: fill(text.showDeliveriesNamed, { name: 'CRM sync' }) }).click()
  await expect
    .element(screen.getByRole('table', { name: fill(text.deliveries.label, { name: 'CRM sync' }) }))
    .toBeVisible()
  expect(await scan(screen.container)).toEqual([])
  await screen.getByRole('button', { name: text.deliveries.close }).click()

  await screen.getByRole('button', { name: text.create }).click()
  const dialog = screen.getByRole('dialog', { name: text.createTitle })
  await dialog.getByRole('button', { name: text.save }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: text.name }))
    .toHaveAttribute('aria-invalid', 'true')
  expect(await scan(document.body)).toEqual([])

  await dialog.getByRole('textbox', { name: text.name }).fill('Status page')
  await dialog.getByRole('textbox', { name: text.url }).fill('https://status.example.com/hooks')
  await dialog.getByRole('checkbox', { name: /^ticket\.resolved/ }).click()
  await dialog.getByRole('button', { name: text.save }).click()
  const secret = screen.getByRole('dialog', { name: 'Status page' })
  await expect.element(secret.getByRole('textbox', { name: text.secret })).toBeVisible()
  expect(await scan(document.body)).toEqual([])
})

const REPORT_PERMISSIONS = ['reports.view', 'tickets.view', 'contacts.view', 'agents.view']

test('the dashboard (KPI tiles, six charts and their tables) has no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: REPORT_PERMISSIONS }) }),
    ),
  )
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('region', { name: 'Tickets created' })).toBeVisible()
  await expect.element(screen.getByRole('heading', { name: 'Median time in status' })).toBeVisible()
  await screen.getByRole('button', { name: copy.reports.showTable }).first().click()

  expect(await scan(screen.container)).toEqual([])
})

test.each([
  ['a bar chart report', 'rpt-t06', 'Response and resolution times'],
  ['a line and area chart report', 'rpt-t02', 'Backlog over time'],
  ['the heatmap report', 'rpt-t11', 'Workload heatmap'],
])('%s has no serious or critical axe violations', async (_kind, key, title) => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: REPORT_PERMISSIONS }) }),
    ),
  )
  const { screen } = await renderApp(`/acme/reports/${key}?compare=previous`)
  await expect.element(screen.getByRole('heading', { level: 1, name: title })).toBeVisible()
  await expect
    .element(screen.getByRole('table', { name: fill(copy.reports.tableLabel, { title }) }))
    .toBeVisible()
  await expect.element(screen.getByRole('heading', { name: copy.reports.totals })).toBeVisible()

  expect(await scan(screen.container)).toEqual([])
})

const ENTITY_PERMISSIONS = ['tickets.view', 'contacts.view', 'agents.view', 'history.view']

test('an Entity 360 overview (KPI tiles, lifecycle trace, related records, trend) has no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ENTITY_PERMISSIONS }) }),
    ),
  )
  const ticket = db.tickets[0]
  const { screen } = await renderApp(`/acme/tickets/${ticket?.id}`)
  await screen.getByRole('tab', { name: copy.entity360.overview }).click()
  await expect.element(screen.getByRole('list', { name: copy.entity360.lifecycle.chartLabel })).toBeVisible()
  await expect.element(screen.getByRole('table', { name: copy.entity360.slaTimers.title })).toBeVisible()
  expect(await scan(screen.container)).toEqual([])

  const agent = AGENT_FIXTURES[0]
  const agentPage = await renderApp(`/acme/agents/${agent?.id}`)
  await expect
    .element(agentPage.screen.getByRole('heading', { name: copy.entity360.trendTitles.backlog_per_day }))
    .toBeVisible()
  expect(await scan(agentPage.screen.container)).toEqual([])
})

test('an Entity 360 history tab with its as-of view has no serious or critical axe violations', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ENTITY_PERMISSIONS }) }),
    ),
  )
  const contact = db.contacts[0]
  const { screen } = await renderApp(`/acme/contacts/${contact?.id}`)
  await screen.getByRole('tab', { name: copy.entity360.history }).click()
  const asOf = screen.getByRole('region', { name: copy.entity360.asOf.title })
  await asOf.getByLabelText(copy.entity360.asOf.label).fill('2026-09-12T10:00')
  await asOf.getByRole('button', { name: copy.entity360.asOf.show }).click()
  await expect.element(asOf.getByRole('table', { name: copy.entity360.asOf.differences })).toBeVisible()
  await expect.element(screen.getByRole('list', { name: copy.entity360.timeline.label })).toBeVisible()

  expect(await scan(screen.container)).toEqual([])
})

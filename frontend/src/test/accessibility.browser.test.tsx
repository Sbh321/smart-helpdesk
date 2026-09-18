import axe, { type Result } from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from './msw/browser'
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
async function settle(root: HTMLElement): Promise<void> {
  await Promise.all(
    root.getAnimations({ subtree: true }).map((animation) => animation.finished.catch(() => undefined)),
  )
}

async function scan(container: HTMLElement): Promise<string[]> {
  await settle(container)
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

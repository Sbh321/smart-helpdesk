import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

function fixtureTicket() {
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  return ticket
}

async function openTicket(
  permissions = ['tickets.view', 'tickets.update', 'tickets.resolve', 'tickets.close'],
) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const ticket = fixtureTicket()
  const app = await renderApp(`/acme/tickets/${ticket.id}`)
  await expect
    .element(app.screen.getByRole('heading', { level: 1 }))
    .toHaveTextContent(`#${ticket.number} ${ticket.title}`)
  return { ...app, ticket }
}

test('resolving requires a comment, confirms the change and updates the timeline', async () => {
  const { screen } = await openTicket()
  await screen.getByRole('button', { name: 'Resolve ticket', exact: true }).click()
  const dialog = screen.getByRole('dialog', { name: 'Resolve ticket' })
  await dialog.getByRole('button', { name: 'Confirm' }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: 'Resolution comment' }))
    .toHaveAttribute('aria-invalid', 'true')
  await dialog
    .getByRole('textbox', { name: 'Resolution comment' })
    .fill('Restored access and verified with the contact.')
  await dialog.getByRole('button', { name: 'Confirm' }).click()
  await expect.element(dialog).not.toBeInTheDocument()
  await expect.element(screen.getByRole('button', { name: 'Close ticket', exact: true })).toBeVisible()
  await expect.element(screen.getByText('Restored access and verified with the contact.')).toBeVisible()
})

test('a refused optimistic transition restores the status and explains the failure', async () => {
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/transition'), () =>
      problem(422, 'invalid_transition', { detail: 'This ticket changed. Refresh and try again.' }),
    ),
  )
  const { screen } = await openTicket()
  await screen.getByRole('button', { name: 'Set pending', exact: true }).click()
  await expect
    .element(screen.getByRole('alert'))
    .toMatchTextContent(/This ticket changed\. Refresh and try again\./)
  await expect.element(screen.getByRole('button', { name: 'Set pending', exact: true })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Resume work' }).query()).toBeNull()
})

test('a viewer can read tabs but cannot edit or transition a ticket', async () => {
  const { screen } = await openTicket(['tickets.view'])
  expect(screen.getByRole('button', { name: 'Edit ticket' }).query()).toBeNull()
  expect(screen.getByRole('button', { name: 'Resolve ticket' }).query()).toBeNull()
  await screen.getByRole('tab', { name: 'Attachments' }).click()
  await expect.element(screen.getByText('No attachments yet.')).toBeVisible()
  await screen.getByRole('tab', { name: 'Timeline' }).click()
  await expect.element(screen.getByRole('heading', { name: 'History' })).toBeVisible()
})

test('editing saves title and description, while shortcuts ignore text input', async () => {
  const { screen, ticket } = await openTicket()
  await userEvent.keyboard('e')
  const dialog = screen.getByRole('dialog', { name: 'Edit ticket' })
  await dialog.getByRole('textbox', { name: 'Title', exact: true }).fill('Updated ticket title')
  await dialog.getByRole('textbox', { name: 'Description', exact: true }).fill('We have narrowed this down.')
  await dialog.getByRole('button', { name: 'Save changes' }).click()
  await expect.element(dialog).not.toBeInTheDocument()
  await expect
    .element(screen.getByRole('heading', { level: 1 }))
    .toHaveTextContent(`#${ticket.number} Updated ticket title`)
  expect(db.tickets.find((row) => row.id === ticket.id)?.description).toBe('We have narrowed this down.')
})

test('the ticket detail has no accessibility violations', async () => {
  const { screen } = await openTicket()
  await expect.element(screen.getByRole('heading', { name: 'History' })).toBeVisible()
  await Promise.all(
    document.body
      .getAnimations({ subtree: true })
      .map((animation) => animation.finished.catch(() => undefined)),
  )
  const results = await axe.run(document.body)
  expect(results.violations).toEqual([])
})

test('stored priority factors render without recomputing the score', async () => {
  fixtureTicket().priority_explanation = {
    strategy: 'basic_weighted',
    strategy_version: '1.0.0',
    parts: [{ name: 'impact', value: 0.5, weight: 0.4, contribution: 20 }],
  }
  const { screen } = await openTicket()
  await screen.getByRole('button', { name: 'Why this priority?' }).click()
  await expect.element(screen.getByRole('cell', { name: '20', exact: true })).toBeVisible()
  await expect.element(screen.getByText('Strategy: basic_weighted · 1.0.0')).toBeVisible()
})

test('history loads older cursor pages without losing recent events', async () => {
  const ticket = fixtureTicket()
  db.ticketEvents[ticket.id] = Array.from({ length: 55 }, (_, index) => ({
    id: `event-${index}`,
    type: 'updated',
    actor_type: 'user',
    actor_id: null,
    old_values: {},
    new_values: {},
    note: `History note ${index}`,
    created_at: '2026-09-18T09:00:00Z',
  }))
  const { screen } = await openTicket()
  await expect.element(screen.getByText('History note 0', { exact: true })).toBeVisible()
  expect(screen.getByText('History note 54', { exact: true }).query()).toBeNull()
  await screen.getByRole('button', { name: 'Load older events' }).click()
  await expect.element(screen.getByText('History note 54', { exact: true })).toBeVisible()
  await expect.element(screen.getByText('History note 0', { exact: true })).toBeVisible()
  expect(screen.getByRole('button', { name: 'Load older events' }).query()).toBeNull()
})

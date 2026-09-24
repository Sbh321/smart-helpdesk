import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { setupMswWorker } from '@/test/msw/browser'
import { db, TEAM_FIXTURES } from '@/test/msw/data'
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
  // The resolution note lands on the timeline (the conversation is the default view since M4-05).
  await screen.getByRole('tab', { name: 'Timeline' }).click()
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
  // Attachments moved into the context panel (M4-05), opened by its summary.
  await screen.getByText('Attachments', { exact: true }).click()
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
  await screen.getByRole('tab', { name: 'Timeline' }).click()
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
  await screen.getByText('Show the calculation').click()
  await expect.element(screen.getByRole('cell', { name: '20', exact: true })).toBeVisible()
  await expect.element(screen.getByText('Strategy: basic_weighted · 1.0.0')).toBeVisible()
})

test('the priority reads as a sentence and a bar per factor before the calculation (M4-06)', async () => {
  fixtureTicket().priority_explanation = {
    strategy: 'basic_weighted',
    strategy_version: '1.0.0',
    parts: [
      { name: 'impact', value: 0.3333, weight: 0.4, contribution: 13.3333 },
      { name: 'urgency', value: 0.6667, weight: 0.3, contribution: 20 },
      { name: 'tier', value: 0, weight: 0.2, contribution: 0 },
      { name: 'age', value: 0.5, weight: 0.1, contribution: 5 },
    ],
  }
  const { screen } = await openTicket()
  await screen.getByRole('button', { name: 'Why this priority?' }).click()
  await expect
    .element(screen.getByText('This ticket scored 38.3 points. Most of it comes from urgency and impact.'))
    .toBeVisible()
  const factors = screen.getByRole('list', { name: 'What the score is made of' }).getByRole('listitem')
  await expect.element(factors.nth(0)).toHaveTextContent('Urgency20.0 points')
  await expect.element(factors.nth(3)).toHaveTextContent('Organisation tier0.0 points')
  // The calculation stays closed until asked for.
  expect(screen.getByRole('cell', { name: '13.3333', exact: true }).query()).toBeNull()
})

test('an assigned ticket says why its Agent was chosen, from the stored explanation (M4-06)', async () => {
  const ticket = fixtureTicket()
  const [first, second] = db.agents
  if (!first || !second) throw new Error('Missing agent fixtures')
  ticket.assigned_agent_id = first.id
  db.assignments[ticket.id] = {
    id: 'assignment-1',
    reason: 'manual',
    team_id: ticket.team_id,
    agent_id: first.id,
    previous_agent_id: null,
    assigned_by_user_id: 'user-1',
    explanation: {
      strategy: 'least_loaded',
      strategy_version: '1.0.0',
      ticket_id: ticket.id,
      outcome: 'assigned',
      selection: 'manual',
      agent_id: first.id,
      recommended_agent_id: second.id,
      manual_override: true,
      override_reason: 'off_shift',
      ranking: [
        { rank: 1, agent_id: second.id, open_tickets: 1, capacity: 5, load: 0.2, last_assigned_at: null },
      ],
      excluded: [{ agent_id: first.id, reason: 'off_shift' }],
    },
    created_at: '2026-09-18T09:00:00Z',
  }
  const { screen } = await openTicket(['tickets.view', 'tickets.update', 'tickets.assign', 'agents.view'])
  const context = screen.getByRole('complementary', { name: 'Ticket context' })
  await context.getByText('Assignment', { exact: true }).click()
  await expect
    .element(
      context.getByText(`${first.user.name} was chosen by hand although not eligible: outside their shift.`),
    )
    .toBeVisible()
  await context.getByText('Show the ranking').click()
  await expect
    .element(context.getByRole('list', { name: /Eligible Agents when it was decided/ }))
    .toHaveTextContent(`${second.user.name}: 1 of 5 open`)
  await expect.element(context.getByText('Strategy: least_loaded · 1.0.0')).toBeVisible()
})

test('the assignment reason is only shown with tickets.assign', async () => {
  const ticket = fixtureTicket()
  db.assignments[ticket.id] = {
    id: 'assignment-1',
    reason: 'auto',
    team_id: null,
    agent_id: null,
    previous_agent_id: null,
    assigned_by_user_id: null,
    explanation: { outcome: 'no_eligible_agent', ranking: [], excluded: [] },
    created_at: '2026-09-18T09:00:00Z',
  }
  const { screen } = await openTicket()
  await screen.getByText('Assignment', { exact: true }).click()
  expect(screen.getByRole('heading', { name: 'Why this Agent' }).query()).toBeNull()
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
  await screen.getByRole('tab', { name: 'Timeline' }).click()
  await expect.element(screen.getByText('History note 0', { exact: true })).toBeVisible()
  expect(screen.getByText('History note 54', { exact: true }).query()).toBeNull()
  await screen.getByRole('button', { name: 'Load older events' }).click()
  await expect.element(screen.getByText('History note 54', { exact: true })).toBeVisible()
  await expect.element(screen.getByText('History note 0', { exact: true })).toBeVisible()
  expect(screen.getByRole('button', { name: 'Load older events' }).query()).toBeNull()
})

test('a timeline change reads as names and labels, never as raw ids (M4-03)', async () => {
  const ticket = fixtureTicket()
  const team = TEAM_FIXTURES[0]
  db.ticketEvents[ticket.id] = [
    {
      id: 'event-readable',
      type: 'assigned',
      actor_type: 'system',
      actor_id: null,
      old_values: { team_id: null, status: 'open' },
      new_values: { team_id: team?.id ?? null, status: 'assigned' },
      note: null,
      created_at: '2026-09-18T09:00:00Z',
    },
  ]
  // agents.view lets the record-name lookup resolve the team id to its name.
  const { screen } = await openTicket(['tickets.view', 'agents.view'])
  await screen.getByRole('tab', { name: 'Timeline' }).click()

  // Before M4-03 this row read "team_id: null → 01a0c549-1f64-71de-…" and "status: open → assigned".
  // The line is built from several elements (label, previous value, arrow, new value), so the row is
  // matched by its text content rather than by one exact string.
  const line = (text: RegExp) => screen.getByRole('listitem').filter({ hasText: text }).first()
  await expect.element(line(new RegExp(`Team:\\s*Empty\\s*→\\s*${team?.name}`))).toBeVisible()
  await expect.element(line(/Status:\s*Open\s*→\s*Assigned/)).toBeVisible()
  expect(screen.getByText('team_id', { exact: false }).query()).toBeNull()
})

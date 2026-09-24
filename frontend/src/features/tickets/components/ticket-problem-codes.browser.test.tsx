import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db, NOW } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/** The stable problem codes of docs/07-api/errors.md the ticket detail explains itself. */
const worker = setupMswWorker()

const PERMISSIONS = [
  'tickets.view',
  'tickets.update',
  'tickets.resolve',
  'tickets.close',
  'tickets.reopen',
  'tickets.assign',
  'agents.view',
]

function ticketWith(status: string) {
  const ticket = db.tickets.find((row) => row.status === status)
  if (!ticket) throw new Error(`Missing ${status} ticket fixture`)
  return ticket
}

async function open(ticket: { id: string; number: number; title: string }) {
  worker.use(
    http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions: PERMISSIONS }) })),
  )
  const app = await renderApp(`/acme/tickets/${ticket.id}`)
  await expect
    .element(app.screen.getByRole('heading', { level: 1 }))
    .toHaveTextContent(`#${ticket.number} ${ticket.title}`)
  return app
}

test('resolution_comment_required is shown on the comment field, not in a banner', async () => {
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/transition'), () =>
      problem(422, 'resolution_comment_required', {
        detail: 'Add a resolution comment before resolving this ticket.',
        meta: { field: 'comment' },
      }),
    ),
  )
  const { screen } = await open(ticketWith('in_progress'))
  await screen.getByRole('button', { name: 'Resolve ticket', exact: true }).click()
  const dialog = screen.getByRole('dialog', { name: 'Resolve ticket' })
  const comment = dialog.getByRole('textbox', { name: 'Resolution comment' })
  await comment.fill('Done.')
  await dialog.getByRole('button', { name: 'Confirm' }).click()
  await expect.element(comment).toHaveAttribute('aria-invalid', 'true')
  await expect.element(comment).toHaveAccessibleDescription(copy.problems.resolutionCommentRequired)
  await expect.element(dialog).toBeVisible()
  expect(dialog.getByText(copy.tickets.detail.failed).query()).toBeNull()
})

for (const [reason, message] of [
  ['closed_as_duplicate', copy.problems.closedAsDuplicate],
  ['reopen_window_expired', copy.problems.reopenWindowExpired],
] as const) {
  test(`invalid_transition (${reason}) gets a friendly explanation`, async () => {
    worker.use(
      http.post(apiUrl('/tickets/{ticket}/transition'), () =>
        problem(422, 'invalid_transition', {
          detail: 'A ticket cannot move from closed to in_progress.',
          meta: { from: 'closed', to: 'in_progress', allowed: [], reason },
        }),
      ),
    )
    const { screen } = await open(ticketWith('closed'))
    await screen.getByRole('button', { name: 'Reopen ticket', exact: true }).click()
    await expect.element(screen.getByRole('alert')).toMatchTextContent(message)
    expect(screen.getByText(/cannot move from closed/).query()).toBeNull()
  })
}

function seedSuggestion(ticketId: string) {
  const candidate = db.tickets[5]
  if (!candidate) throw new Error('Missing candidate fixture')
  const row = {
    id: 'suggestion-1',
    ticket_id: ticketId,
    candidate_ticket_id: candidate.id,
    candidate: { number: candidate.number, title: candidate.title, status: candidate.status },
    score: 0.82,
    shared_words: ['invoice', 'wrong'],
    strategy: 'word_overlap',
    strategy_version: '1.0.0',
    decision: 'pending',
    decided_at: null,
    created_at: NOW,
  }
  db.duplicates[ticketId] = [row]
  return row
}

test('the Duplicates tab lists a suggestion and dismisses it', async () => {
  const ticket = ticketWith('open')
  seedSuggestion(ticket.id)
  const { screen } = await open(ticket)
  await screen.getByText('Duplicates', { exact: true }).click()
  await expect.element(screen.getByText('82% similar')).toBeVisible()
  // Match details (M4-06): the shared words as tokens and the strategy that found them.
  const words = screen.getByRole('list', { name: 'Shared words' })
  await expect.element(words.getByRole('listitem').nth(0)).toHaveTextContent('invoice')
  await expect.element(words.getByRole('listitem').nth(1)).toHaveTextContent('wrong')
  await expect.element(screen.getByText('Found by word_overlap · 1.0.0')).toBeVisible()
  await screen.getByRole('button', { name: 'Dismiss' }).click()
  await expect.element(screen.getByText('Suggestion dismissed.')).toBeVisible()
  await expect.element(screen.getByText('Dismissed', { exact: true })).toBeVisible()
  expect(screen.getByRole('button', { name: 'Dismiss' }).query()).toBeNull()
})

test('accepting a suggestion closes the ticket as a duplicate after a confirmation', async () => {
  const ticket = ticketWith('open')
  const row = seedSuggestion(ticket.id)
  const { screen } = await open(ticket)
  await screen.getByText('Duplicates', { exact: true }).click()
  await screen.getByRole('button', { name: 'Mark as duplicate' }).click()
  const dialog = screen.getByRole('dialog', { name: 'Mark ticket as duplicate?' })
  await dialog.getByRole('button', { name: 'Mark as duplicate' }).click()
  await expect.element(screen.getByText('Ticket marked as duplicate.')).toBeVisible()
  expect(db.tickets.find((item) => item.id === ticket.id)).toMatchObject({
    status: 'closed',
    duplicate_of_id: row.candidate_ticket_id,
  })
})

test('already_decided on dismiss refetches the list and says so without an error', async () => {
  const ticket = ticketWith('open')
  const row = seedSuggestion(ticket.id)
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/duplicates/{candidate}/dismiss'), () => {
      row.decision = 'accepted'
      return problem(409, 'already_decided', { detail: 'The suggestion was already accepted.' })
    }),
  )
  const { screen } = await open(ticket)
  await screen.getByText('Duplicates', { exact: true }).click()
  await screen.getByRole('button', { name: 'Dismiss' }).click()
  await expect.element(screen.getByText(copy.problems.alreadyDecided)).toBeVisible()
  await expect.element(screen.getByText('Accepted', { exact: true })).toBeVisible()
  expect(screen.getByText(copy.tickets.duplicates.dismissFailed).query()).toBeNull()
})

test('already_decided on accept closes the confirmation and refetches', async () => {
  const ticket = ticketWith('open')
  const row = seedSuggestion(ticket.id)
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/mark-duplicate'), () => {
      row.decision = 'dismissed'
      return problem(409, 'already_decided', { detail: 'The suggestion was already dismissed.' })
    }),
  )
  const { screen } = await open(ticket)
  await screen.getByText('Duplicates', { exact: true }).click()
  await screen.getByRole('button', { name: 'Mark as duplicate' }).click()
  await screen
    .getByRole('dialog', { name: 'Mark ticket as duplicate?' })
    .getByRole('button', { name: 'Mark as duplicate' })
    .click()
  await expect.element(screen.getByText(copy.problems.alreadyDecided)).toBeVisible()
  await expect
    .element(screen.getByRole('dialog', { name: 'Mark ticket as duplicate?' }))
    .not.toBeInTheDocument()
  await expect.element(screen.getByText('Dismissed', { exact: true })).toBeVisible()
})

test('the assignment dialog ranks Agents by name and assigns one', async () => {
  const ticket = ticketWith('open')
  const { screen } = await open(ticket)
  await screen.getByRole('button', { name: 'Assignment', exact: true }).click()
  const dialog = screen.getByRole('dialog', { name: 'Assignment' })
  await expect.element(dialog.getByRole('table', { name: 'Eligible Agents, best first' })).toBeVisible()
  const first = db.agents.find((agent) => agent.availability === 'available')
  if (!first) throw new Error('Missing available Agent fixture')
  await expect.element(dialog.getByText(/^[0-9a-f-]{36}$/)).not.toBeInTheDocument()
  await dialog.getByRole('button', { name: `Assign to ${first.user.name}` }).click()
  await expect.element(screen.getByText('Ticket assigned.')).toBeVisible()
  expect(db.tickets.find((row) => row.id === ticket.id)?.assigned_agent_id).toBe(first.id)
})

test('already_assigned refetches the ticket and shows who has it', async () => {
  const ticket = ticketWith('open')
  const winner = db.agents[0]
  if (!winner) throw new Error('Missing Agent fixture')
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/auto-assign'), () => {
      ticket.assigned_agent_id = winner.id
      ticket.status = 'assigned'
      return problem(409, 'already_assigned', { detail: 'The ticket is already assigned.' })
    }),
  )
  const { screen } = await open(ticket)
  await screen.getByRole('button', { name: 'Assignment', exact: true }).click()
  const dialog = screen.getByRole('dialog', { name: 'Assignment' })
  await dialog.getByRole('button', { name: 'Auto-assign' }).click()
  await expect.element(screen.getByText(copy.problems.alreadyAssigned)).toBeVisible()
  await expect.element(dialog.getByText('Current Agent')).toBeVisible()
  await expect.element(dialog.getByText(winner.user.name)).toBeVisible()
  expect(dialog.getByText(copy.assignment.failed).query()).toBeNull()
})

test('no_eligible_agent lists the exclusions of the attempt', async () => {
  const ticket = ticketWith('open')
  const [busy, away] = db.agents
  if (!busy || !away) throw new Error('Missing Agent fixtures')
  worker.use(
    http.post(apiUrl('/tickets/{ticket}/auto-assign'), () =>
      problem(422, 'no_eligible_agent', {
        detail: 'No agent is eligible for this ticket. Assign it manually or change the team.',
        meta: {
          ticket_id: ticket.id,
          team_id: null,
          exclusions: [
            { agent_id: busy.id, reason: 'at_capacity' },
            { agent_id: away.id, reason: 'missing_skill', missing_skills: ['billing'] },
          ],
        },
      }),
    ),
  )
  const { screen } = await open(ticket)
  await screen.getByRole('button', { name: 'Assignment', exact: true }).click()
  const dialog = screen.getByRole('dialog', { name: 'Assignment' })
  await dialog.getByRole('button', { name: 'Auto-assign' }).click()
  await expect.element(dialog.getByRole('alert')).toMatchTextContent(copy.problems.noEligibleAgent)
  await expect.element(dialog.getByRole('heading', { name: 'Why nobody could take it (2)' })).toBeVisible()
  await expect.element(dialog.getByText(`${busy.user.name}: At capacity`)).toBeVisible()
  await expect.element(dialog.getByText(`${away.user.name}: Missing skills: billing`)).toBeVisible()
})

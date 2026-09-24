import { expect, type Page, test } from '@playwright/test'
import { stateFor, users } from './support/env'
import { expectOk, sessionApi, ticketDefaults } from './support/session'

/**
 * Milestone 2 golden path through the UI against the Compose stack (docs/10-quality/definition-of-done.md
 * §Roadmap week, M2): create → automatic priority → duplicate suggestion → automatic assignment → reply →
 * pending → resolve → close. Needs the demo dataset (`acme`, priya@acme.test with an Agent profile).
 */

const workspace = users.priya.workspace

test.use({ storageState: stateFor('priya') })

/**
 * Set-up through the API, not assertions: the agent needs a free slot for automatic assignment, and an
 * earlier ticket with the same wording is what the duplicate suggestion should find.
 */
async function prepare(page: Page, title: string): Promise<number> {
  const api = sessionApi(page.context())
  const me = await api.json<{ data: { agent_profile?: { id: string } } }>('/me')
  const agentId = me.data.agent_profile?.id
  expect(agentId, 'the signed-in user needs an Agent profile').toBeTruthy()
  await expectOk(await api.patch(`/agents/${agentId}`, { capacity: 100, availability: 'available' }))

  const twin = await api.post('/tickets', {
    title,
    description: 'The VPN connection for the sales team drops every hour since Monday.',
    ...(await ticketDefaults(api)),
    impact: 2,
    urgency: 2,
  })
  await expectOk(twin)
  return (await twin.json()).data.number
}

test('create, prioritise, suggest duplicates, assign, reply, pend, resolve and close a ticket', async ({
  page,
}) => {
  const stamp = Date.now().toString(36)
  const title = `Golden path ${stamp}: VPN drops every hour`
  const twinNumber = await prepare(page, title)

  // Create: the duplicate preview runs while typing.
  await page.goto(`/${workspace}/tickets`)
  await page.getByRole('button', { name: 'New ticket' }).first().click()
  const dialog = page.getByRole('dialog', { name: 'New ticket' })
  await dialog.getByLabel('Title').fill(title)
  await dialog
    .getByLabel('Description')
    .fill('The VPN connection for the sales team drops every hour since Monday.')
  await dialog.getByRole('combobox', { name: 'Contact' }).fill('a')
  await page.getByRole('option').first().click()
  await dialog.getByRole('combobox', { name: 'Category' }).click()
  await page.getByRole('option').first().click()
  await dialog.getByRole('combobox', { name: 'Impact' }).click()
  await page.getByRole('option', { name: '3 · Department' }).click()
  await dialog.getByRole('combobox', { name: 'Urgency' }).click()
  await page.getByRole('option', { name: '3 · High' }).click()
  await dialog.getByRole('button', { name: 'Create ticket' }).click()
  await expect(dialog).toBeHidden()
  const created = await page.getByText(/^Ticket #\d+ created\.$/).textContent()
  const number = created?.match(/#(\d+)/)?.[1]
  expect(number).toBeTruthy()

  // The list stays open after creating; the new ticket is opened from its row.
  await page.getByRole('searchbox').first().fill(stamp)
  await page.getByRole('cell', { name: `#${number}`, exact: true }).click()

  // Detail: priority scored (impact 3, urgency 3 → 50.0, P2) and the ticket assigned automatically.
  await expect(page).toHaveURL(new RegExp(`/${workspace}/tickets/[0-9a-f-]{36}$`))
  await expect(page.getByRole('heading', { level: 1 })).toContainText(title)
  await expect(page.getByText('P2 High').first()).toBeVisible()
  await page.getByRole('button', { name: 'Why this priority?' }).click()
  // In words first, the calculation one click away (M4-06).
  await expect(page.getByText('This ticket scored 50.0 points.', { exact: false })).toBeVisible()
  await page.getByText('Show the calculation').click()
  await expect(page.getByText('basic_weighted_priority', { exact: false }).first()).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByText('Assigned', { exact: true }).first()).toBeVisible()
  // Why the Agent was chosen stays on the ticket after the decision (M4-06).
  const context = page.getByRole('complementary', { name: 'Ticket context' })
  await context.getByText('Assignment', { exact: true }).click()
  await expect(
    context.getByText(
      /was picked automatically: (the lowest load of \d+ eligible Agents|the only eligible Agent)/,
    ),
  ).toBeVisible()

  // Duplicates: the earlier report with the same wording is suggested, in the context panel (M4-05).
  const duplicates = page.getByRole('complementary', { name: 'Ticket context' })
  await duplicates.getByText('Duplicates', { exact: true }).click()
  await expect(duplicates).toContainText(`#${twinNumber}`)

  // Reply: a public reply meets the first-response target.
  await page.getByRole('tab', { name: 'Comments' }).click()
  await expect(page.getByRole('radio', { name: 'Public reply' })).toBeChecked()
  await page.getByRole('textbox', { name: 'Message' }).fill('We are looking at the VPN logs now.')
  await page.getByRole('button', { name: 'Send reply to the Contact' }).click()
  await expect(page.getByText('We are looking at the VPN logs now.')).toBeVisible()
  // SLA (M4-07): the reply met the response timer; the header now only carries the resolution clock.
  await expect(context.getByRole('heading', { name: 'First response' })).toBeVisible()
  await expect(context.getByText('Met', { exact: true })).toBeVisible()
  const slaLine = page.getByRole('list', { name: 'SLA timers' })
  await expect(slaLine).toContainText(/Resolution(On track|Due soon)/)
  await expect(slaLine).not.toContainText('Response')

  // Lifecycle: in progress → pending → resolved (with a resolution) → closed.
  await page.getByRole('button', { name: 'Start work' }).click()
  await expect(page.getByText('In progress', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Set pending' }).click()
  await expect(page.getByText('Pending', { exact: true }).first()).toBeVisible()
  // Pending stops the clock, and the header says so instead of counting down.
  await expect(slaLine).toHaveText('ResolutionPaused')
  await page.getByRole('button', { name: 'Resolve ticket' }).click()
  const resolve = page.getByRole('dialog', { name: 'Resolve ticket' })
  await resolve
    .getByRole('textbox', { name: 'Resolution comment' })
    .fill('Replaced the VPN gateway certificate.')
  await resolve.getByRole('button', { name: 'Confirm' }).click()
  await expect(resolve).toBeHidden()
  await expect(page.getByText('Resolved', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Close ticket' }).click()
  await page.getByRole('dialog', { name: 'Close ticket' }).getByRole('button', { name: 'Confirm' }).click()
  await expect(page.getByText('Closed', { exact: true }).first()).toBeVisible()
  await expect(page.getByRole('button', { name: 'Reopen ticket' })).toBeVisible()

  // History: every step is on the timeline.
  await page.getByRole('tab', { name: 'Timeline' }).click()
  await expect(page.getByText(/status changed/i).first()).toBeVisible()
})

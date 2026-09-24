import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { setupMswWorker } from '@/test/msw/browser'
import { CALENDAR_FIXTURES, db, SLA_POLICY_FIXTURES, type SlaTimerResource } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const MINUTE = 60_000
const at = (offsetMinutes: number) => new Date(Date.now() + offsetMinutes * MINUTE).toISOString()

function timer(kind: SlaTimerResource['kind'], overrides: Partial<SlaTimerResource>): SlaTimerResource {
  return {
    id: `${kind}-timer`,
    kind,
    cycle: 1,
    state: 'running',
    target_minutes: kind === 'first_response' ? 60 : 480,
    started_at: at(-120),
    warning_at: at(180),
    due_at: at(300),
    paused_at: null,
    paused_total_seconds: 0,
    warned_at: null,
    breached_at: null,
    met_at: null,
    cancelled_at: null,
    policy_id: SLA_POLICY_FIXTURES[0]?.id ?? '',
    policy_version: 1,
    warning_fraction: 0.8,
    calendar_id: CALENDAR_FIXTURES[0]?.id ?? null,
    strategy: 'business_calendar_sla',
    strategy_version: '1.0.0',
    ...overrides,
  }
}

async function openTicket() {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'tickets.update'] }) }),
    ),
  )
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  const app = await renderApp(`/acme/tickets/${ticket.id}`)
  await expect
    .element(app.screen.getByRole('heading', { level: 1 }))
    .toHaveTextContent(`#${ticket.number} ${ticket.title}`)
  return { ...app, ticket }
}

test('pending pauses the clock, resuming restarts it, and near the deadline it reads due soon (M4-07)', async () => {
  const ticket = db.tickets[2]
  if (!ticket) throw new Error('Missing ticket fixture')
  const response = timer('first_response', { state: 'met', met_at: at(-90), due_at: at(-60) })
  db.slaTimers[ticket.id] = [response, timer('resolution', {})]
  const { screen, queryClient } = await openTicket()
  const header = screen.getByRole('list', { name: 'SLA timers' })
  const panel = screen.getByRole('complementary', { name: 'Ticket context' })

  // Running: the header names the promise and the time left; a met response timer is not repeated there.
  await expect.element(header).toMatchTextContent(/Resolution\s*On track · 5 hours left/)
  expect(header.getByText('Response').query()).toBeNull()
  await expect.element(panel.getByRole('heading', { name: 'First response' })).toBeVisible()
  await expect.element(panel.getByText('Met', { exact: true })).toBeVisible()

  // Which policy and calendar produced the deadline, one click away.
  await panel.getByText('Policy and calendar').nth(1).click()
  await expect.element(panel.getByText('Standard support · version 1').nth(1)).toBeVisible()
  await expect.element(panel.getByText('Kathmandu office · Asia/Kathmandu').nth(1)).toBeVisible()
  await expect.element(panel.getByText('8 hours of working time')).toBeVisible()

  // Pending: paused reads as paused, with no countdown that would imply the clock runs.
  db.slaTimers[ticket.id] = [response, timer('resolution', { state: 'paused', paused_at: at(-5) })]
  await queryClient.invalidateQueries()
  await expect.element(header).toMatchTextContent(/^Resolution\s*Paused$/)
  await expect.element(panel.getByText(/^Paused since /)).toBeVisible()
  await expect.element(panel.getByText(/clock is stopped while the ticket is pending/)).toBeVisible()
  expect(panel.getByText(/left$/).query()).toBeNull()

  // Resumed, with the paused hour added to the deadline, and now close to it.
  db.slaTimers[ticket.id] = [
    response,
    timer('resolution', { state: 'warning', due_at: at(20), warned_at: at(-1), paused_total_seconds: 3600 }),
  ]
  await queryClient.invalidateQueries()
  await expect.element(header).toMatchTextContent(/Resolution\s*Due soon · 20 minutes left/)
  await expect.element(panel.getByText('1 hour', { exact: true })).toBeVisible()
  // Screen readers hear the states once, not every tick of the countdown.
  expect(panel.element().querySelector('[aria-live="polite"]')?.textContent).toBe(
    'Response: Met. Resolution: Due soon.',
  )

  const results = await axe.run(document.body)
  expect(results.violations).toEqual([])
})

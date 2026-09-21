import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { AGENT_FIXTURES } from '@/test/msw/agents'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

function agentSession() {
  const agent = AGENT_FIXTURES[0]
  if (!agent) throw new Error('Missing Agent fixture')
  return sessionFixture({
    permissions: ['agents.view'],
    agent_profile: {
      id: agent.id,
      capacity: agent.capacity,
      availability: agent.availability,
      active_ticket_count: agent.active_ticket_count,
    },
  })
}

test('an Agent changes availability from the Topbar', async () => {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: agentSession() })))
  const { screen } = await renderApp('/acme/tickets')
  await screen.getByRole('button', { name: /Availability/ }).click()
  await screen.getByRole('menuitem', { name: 'Away' }).click()
  await expect.element(screen.getByRole('button', { name: /Away/ })).toBeVisible()
})

test('a rejected change restores availability and reports the problem', async () => {
  worker.use(
    http.get(apiUrl('/me'), () => HttpResponse.json({ data: agentSession() })),
    http.patch(apiUrl('/agents/{agent}'), () =>
      problem(409, 'conflict', { detail: 'Availability could not be changed.' }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  await screen.getByRole('button', { name: /Availability/ }).click()
  await screen.getByRole('menuitem', { name: 'Away' }).click()
  await expect.element(screen.getByRole('button', { name: /Available/ })).toBeVisible()
  await expect.element(screen.getByRole('alert')).toHaveTextContent('Availability could not be changed.')
})

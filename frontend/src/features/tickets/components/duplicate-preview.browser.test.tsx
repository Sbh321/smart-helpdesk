import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

async function newTicket() {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view', 'tickets.create'] }) }),
    ),
  )
  const app = await renderApp('/acme/tickets/new')
  await expect.element(app.screen.getByText(copy.tickets.duplicates.previewHint)).toBeVisible()
  return app
}

test('the preview lists similar tickets once title and description are typed', async () => {
  const { screen } = await newTicket()
  await screen.getByRole('textbox', { name: 'Title' }).fill('Password reset email missing')
  await screen.getByRole('textbox', { name: 'Description' }).fill('The reset email never arrives.')
  await expect.element(screen.getByText(/% similar/).first()).toBeVisible()
  await expect.element(screen.getByText(/Shared words: .*password/).first()).toBeVisible()
})

test('a throttled preview (429) backs off silently: one request, no error', async () => {
  let requests = 0
  worker.use(
    http.post(apiUrl('/tickets/preview-duplicates'), () => {
      requests += 1
      return problem(429, 'rate_limited', { detail: 'Too many requests.' })
    }),
  )
  const { screen } = await newTicket()
  await screen.getByRole('textbox', { name: 'Title' }).fill('Password reset email missing')
  await screen.getByRole('textbox', { name: 'Description' }).fill('The reset email never arrives.')
  await expect.poll(() => requests).toBe(1)
  await new Promise((resolve) => setTimeout(resolve, 600))
  expect(requests).toBe(1)
  expect(screen.getByText(copy.tickets.duplicates.previewFailed).query()).toBeNull()
  expect(screen.getByText('Too many requests.').query()).toBeNull()
  await expect.element(screen.getByText(copy.tickets.duplicates.previewHint)).toBeVisible()
})

test('any other preview failure is said in the panel', async () => {
  worker.use(http.post(apiUrl('/tickets/preview-duplicates'), () => problem(500, 'server_error')))
  const { screen } = await newTicket()
  await screen.getByRole('textbox', { name: 'Title' }).fill('Password reset email missing')
  await screen.getByRole('textbox', { name: 'Description' }).fill('The reset email never arrives.')
  await expect.element(screen.getByText(copy.tickets.duplicates.previewFailed)).toBeVisible()
})

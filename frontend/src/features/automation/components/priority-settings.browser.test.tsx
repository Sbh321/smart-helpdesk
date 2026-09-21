import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const rules = copy.priority.validation

async function openSettings(permissions = ['settings.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  return renderApp('/acme/settings/priority')
}

test('the defaults preview the five worked examples', async () => {
  const { screen } = await openSettings()
  await screen.getByRole('button', { name: 'Preview scores' }).click()
  const table = screen.getByRole('table', { name: 'Scores of the worked examples' })
  await expect.element(table).toBeVisible()
  await expect.element(table.getByText('Impact 4, urgency 4, Enterprise, waited 0 h')).toBeVisible()
  await expect.element(table.getByText('90.0')).toBeVisible()
  await expect.element(table.getByText('P1', { exact: true })).toBeVisible()
})

test('weights that do not add up and unordered thresholds stop on the client', async () => {
  let requests = 0
  worker.use(
    http.post(apiUrl('/settings/automation/priority/preview'), () => {
      requests += 1
      return problem(500, 'server_error')
    }),
  )
  const { screen } = await openSettings()
  await screen.getByRole('spinbutton', { name: 'Impact' }).fill('0.9')
  await screen.getByRole('spinbutton', { name: 'P2' }).fill('80')
  await screen.getByRole('spinbutton', { name: 'Age reaches its full weight after (hours)' }).fill('')
  await screen.getByRole('button', { name: 'Preview scores' }).click()
  await expect.element(screen.getByText(rules.weightSum)).toBeVisible()
  await expect
    .element(screen.getByRole('spinbutton', { name: 'P2' }))
    .toHaveAccessibleDescription(rules.thresholdOrderP2)
  await expect.element(screen.getByText(rules.ageFullHours)).toBeVisible()
  expect(requests).toBe(0)
  await screen.getByRole('spinbutton', { name: 'Impact' }).fill('0.4')
  await expect.element(screen.getByText(rules.weightSum)).not.toBeInTheDocument()
})

test('a 422 lands on the threshold it names', async () => {
  worker.use(
    http.post(apiUrl('/settings/automation/priority/preview'), () =>
      problem(422, 'validation_failed', {
        errors: {
          'thresholds.P3': ['The P3 threshold must be below P2.'],
          weights: ['The weights must sum to 1.'],
        },
      }),
    ),
  )
  const { screen } = await openSettings()
  await screen.getByRole('button', { name: 'Preview scores' }).click()
  await expect
    .element(screen.getByRole('spinbutton', { name: 'P3' }))
    .toHaveAccessibleDescription('The P3 threshold must be below P2.')
  await expect.element(screen.getByText('The weights must sum to 1.')).toBeVisible()
})

test('priority settings need settings.manage', async () => {
  const { screen } = await openSettings(['tickets.view'])
  await expect.element(screen.getByText(/permission/i)).toBeVisible()
})

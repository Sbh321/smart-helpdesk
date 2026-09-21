import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()

function signIn(permissions: string[]) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

test('a manager creates an SLA policy and sees it in the list', async () => {
  signIn(['tickets.view', 'sla.manage'])
  const { screen } = await renderApp('/acme/settings/sla')
  await screen.getByRole('button', { name: 'Add policy' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Premium support')
  await screen.getByRole('spinbutton', { name: 'P1 First response (minutes)' }).fill('15')
  await screen.getByRole('button', { name: 'Save policy' }).click()
  await expect.element(screen.getByText('SLA policy saved.')).toBeVisible()
  await expect.element(screen.getByText(/Premium support/)).toBeVisible()
  expect(db.slaPolicies.find((row) => row.name === 'Premium support')?.targets[0]).toMatchObject({
    priority_level: 'P1',
    first_response_minutes: 15,
    resolution_minutes: 480,
  })
})

test('the policy form validates on the client before any request', async () => {
  signIn(['tickets.view', 'sla.manage'])
  let requests = 0
  worker.use(
    http.post(apiUrl('/sla-policies'), () => {
      requests += 1
      return problem(500, 'server_error')
    }),
  )
  const { screen } = await renderApp('/acme/settings/sla')
  await screen.getByRole('button', { name: 'Add policy' }).click()
  await screen.getByRole('spinbutton', { name: 'Warning fraction' }).fill('2')
  await screen.getByRole('spinbutton', { name: 'P2 Resolution (minutes)' }).fill('10')
  await screen.getByRole('button', { name: 'Save policy' }).click()
  await expect.element(screen.getByText('Enter a name.')).toBeVisible()
  await expect.element(screen.getByText('Enter a number from 0.10 to 0.95.')).toBeVisible()
  await expect
    .element(screen.getByText('Resolution cannot be shorter than the first response.'))
    .toBeVisible()
  await expect.element(screen.getByRole('textbox', { name: 'Name' })).toHaveAttribute('aria-invalid', 'true')
  expect(requests).toBe(0)
})

test('a 422 lands on the policy fields it names, including a target row', async () => {
  signIn(['tickets.view', 'sla.manage'])
  worker.use(
    http.post(apiUrl('/sla-policies'), () =>
      problem(422, 'validation_failed', {
        errors: {
          name: ['An SLA policy with this name already exists.'],
          'targets.2.resolution_minutes': ['The P3 resolution target is too long.'],
        },
      }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/sla')
  await screen.getByRole('button', { name: 'Add policy' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Standard support')
  await screen.getByRole('button', { name: 'Save policy' }).click()
  await expect.element(screen.getByText('An SLA policy with this name already exists.')).toBeVisible()
  await expect
    .element(screen.getByRole('spinbutton', { name: 'P3 Resolution (minutes)' }))
    .toHaveAccessibleDescription('The P3 resolution target is too long.')
})

test('editing a policy starts from its stored targets', async () => {
  signIn(['tickets.view', 'sla.manage'])
  const { screen } = await renderApp('/acme/settings/sla')
  await screen.getByRole('button', { name: 'Edit Standard support' }).click()
  await expect.element(screen.getByRole('spinbutton', { name: 'P1 Resolution (minutes)' })).toHaveValue(240)
  await screen.getByRole('spinbutton', { name: 'P1 Resolution (minutes)' }).fill('120')
  await screen.getByRole('button', { name: 'Save policy' }).click()
  await expect.poll(() => db.slaPolicies[0]?.targets[0]?.resolution_minutes).toBe(120)
})

test('a viewer reads policies without edit controls', async () => {
  signIn(['tickets.view'])
  const { screen } = await renderApp('/acme/settings/sla')
  await expect.element(screen.getByText(/Standard support/)).toBeVisible()
  await expect.element(screen.getByRole('button', { name: 'Add policy' })).not.toBeInTheDocument()
})

test('a calendar refuses overlapping windows and saves once corrected', async () => {
  signIn(['tickets.view', 'calendars.manage'])
  const { screen } = await renderApp('/acme/settings/calendars')
  await screen.getByRole('button', { name: 'Add calendar' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Support desk')
  await screen.getByRole('button', { name: 'Add window to Monday' }).click()
  await screen.getByRole('button', { name: 'Save calendar' }).click()
  await expect.element(screen.getByText('This window overlaps another one on the same day.')).toBeVisible()
  await screen.getByRole('button', { name: 'Remove Monday window 2' }).click()
  await screen.getByRole('button', { name: 'Save calendar' }).click()
  await expect.element(screen.getByText('Business calendar saved.')).toBeVisible()
  expect(db.calendars.find((row) => row.name === 'Support desk')?.weekly_hours).toEqual({
    mon: [['10:00', '17:00']],
  })
})

test('a calendar needs a real time zone and at least one window', async () => {
  signIn(['tickets.view', 'calendars.manage'])
  const { screen } = await renderApp('/acme/settings/calendars')
  await screen.getByRole('button', { name: 'Add calendar' }).click()
  await screen.getByRole('textbox', { name: 'Name' }).fill('Nowhere')
  await screen.getByRole('combobox', { name: 'IANA time zone' }).fill('Mars/Olympus')
  await screen.getByRole('button', { name: 'Remove Monday window 1' }).click()
  await screen.getByRole('button', { name: 'Save calendar' }).click()
  await expect.element(screen.getByText('Enter an IANA time zone such as Asia/Kathmandu.')).toBeVisible()
  await expect.element(screen.getByText('Add at least one working window.')).toBeVisible()
})

test('a holiday is added, a taken date is shown on the date field, and removal asks first', async () => {
  signIn(['tickets.view', 'calendars.manage'])
  const { screen } = await renderApp('/acme/settings/calendars')
  await screen.getByRole('button', { name: 'Add holiday to Kathmandu office' }).click()
  await screen.getByLabelText('Date').fill('2026-10-20')
  await screen.getByRole('textbox', { name: 'Name' }).fill('Dashain again')
  await screen.getByRole('button', { name: 'Save holiday' }).click()
  await expect
    .element(screen.getByLabelText('Date'))
    .toHaveAccessibleDescription('The date has already been taken.')
  await screen.getByLabelText('Date').fill('2026-11-10')
  await screen.getByRole('button', { name: 'Save holiday' }).click()
  await expect.element(screen.getByText('Holiday added.')).toBeVisible()

  await screen.getByRole('button', { name: 'Remove Dashain', exact: true }).click()
  const dialog = screen.getByRole('alertdialog', { name: 'Remove this holiday?' })
  await expect.element(dialog).toBeVisible()
  await expect.element(dialog.getByRole('button', { name: 'Cancel' })).toHaveFocus()
  expect(db.calendars[0]?.holidays).toHaveLength(2)
  await dialog.getByRole('button', { name: 'Remove' }).click()
  await expect.element(screen.getByText('Holiday removed.')).toBeVisible()
  expect(db.calendars[0]?.holidays.map((row) => row.name)).toEqual(['Dashain again'])
})

import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { page } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/** Roadmap M1-17: the minimal create dialog — client rules, a 422 on a field, and the 201 path. */
const worker = setupMswWorker()
const create = copy.tickets.create

async function openDialog(permissions = ['tickets.view', 'tickets.create']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp('/acme/tickets')
  await app.screen.getByRole('button', { name: create.open }).click()
  const dialog = app.screen.getByRole('dialog', { name: create.title })
  await expect.element(dialog).toBeVisible()
  return { ...app, dialog }
}

async function choose(
  dialog: Awaited<ReturnType<typeof openDialog>>['dialog'],
  label: string,
  option: string,
) {
  await dialog.getByRole('combobox', { name: label }).click()
  await page.getByRole('option', { name: option }).click()
}

test('an empty submit explains every required field', async () => {
  const { dialog } = await openDialog()

  await dialog.getByRole('button', { name: create.submit }).click()

  const rules = copy.tickets.validation
  await expect
    .element(dialog.getByRole('textbox', { name: create.titleLabel }))
    .toHaveAccessibleDescription(rules.titleRequired)
  await expect
    .element(dialog.getByRole('textbox', { name: create.descriptionLabel }))
    .toHaveAccessibleDescription(rules.descriptionRequired)
  await expect
    .element(dialog.getByRole('combobox', { name: create.contactLabel }))
    .toHaveAccessibleDescription(rules.contactRequired)
  await expect
    .element(dialog.getByRole('combobox', { name: create.categoryLabel }))
    .toHaveAccessibleDescription(rules.categoryRequired)
  await expect
    .element(dialog.getByRole('combobox', { name: create.impactLabel }))
    .toHaveAccessibleDescription(`${create.impactDescription} ${rules.levelRequired}`)
})

async function fillValid(dialog: Awaited<ReturnType<typeof openDialog>>['dialog']) {
  await dialog.getByRole('textbox', { name: create.titleLabel }).fill('Printer on fire')
  await dialog.getByRole('textbox', { name: create.descriptionLabel }).fill('The office printer is smoking.')
  await dialog.getByRole('combobox', { name: create.contactLabel }).fill('bina')
  await page.getByRole('option', { name: /Bina Adhikari/ }).click()
  await choose(dialog, create.categoryLabel, 'Technical')
  await choose(dialog, create.impactLabel, copy.tickets.impact[3])
  await choose(dialog, create.urgencyLabel, copy.tickets.urgency[4])
}

test('a 422 from the API lands on the field it names', async () => {
  worker.use(
    http.post(apiUrl('/tickets'), () =>
      problem(422, 'validation_failed', { errors: { title: ['A ticket with this title is already open.'] } }),
    ),
  )
  const { dialog } = await openDialog()
  await fillValid(dialog)

  await dialog.getByRole('button', { name: create.submit }).click()

  await expect
    .element(dialog.getByRole('textbox', { name: create.titleLabel }))
    .toHaveAccessibleDescription('A ticket with this title is already open.')
  await expect.element(dialog).toBeVisible()
})

test('a valid ticket is created, the dialog closes and the list refreshes', async () => {
  let body: Record<string, unknown> | undefined
  let listRequests = 0
  worker.events.on('request:start', async ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname !== '/v1/tickets') return
    if (request.method === 'POST') body = (await request.clone().json()) as Record<string, unknown>
    else listRequests += 1
  })
  const { dialog, screen } = await openDialog()
  await fillValid(dialog)
  const before = listRequests

  await dialog.getByRole('button', { name: create.submit }).click()

  await expect.element(screen.getByText(fill(create.created, { number: 1061 }))).toBeVisible()
  await expect.element(dialog).not.toBeInTheDocument()
  expect(body).toEqual({
    title: 'Printer on fire',
    description: 'The office printer is smoking.',
    contact_id: db.contacts[1]?.id,
    category_id: db.categories[1]?.id,
    impact: 3,
    urgency: 4,
  })
  await expect.poll(() => listRequests).toBeGreaterThan(before)
  worker.events.removeAllListeners()
})

test('without tickets.create there is no "New ticket" button', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/tickets')
  await expect.element(screen.getByRole('table', { name: copy.tickets.list.label })).toBeVisible()
  expect(screen.getByRole('button', { name: create.open }).query()).toBeNull()
})

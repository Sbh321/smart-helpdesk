import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { CONTACT_FIXTURES } from '@/test/msw/contacts'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/** Roadmap M1-15: the contact form's client rules, the API's 422 on the same fields, and the round trip. */
const worker = setupMswWorker()

async function open(path: string, permissions = ['contacts.view', 'contacts.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  return renderApp(path)
}

test('client-side rules put messages on the fields and mark them invalid', async () => {
  const { screen } = await open('/acme/contacts/new')
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.contacts.newTitle })).toBeVisible()

  await screen.getByRole('button', { name: copy.contacts.form.create }).click()

  const name = screen.getByRole('textbox', { name: copy.contacts.form.name })
  await expect.element(name).toHaveAttribute('aria-invalid', 'true')
  await expect.element(name).toHaveAccessibleDescription(copy.contacts.validation.nameRequired)
  await expect
    .element(screen.getByRole('textbox', { name: copy.contacts.form.email }))
    .toHaveAccessibleDescription(copy.contacts.validation.emailRequired)

  await screen.getByRole('textbox', { name: copy.contacts.form.email }).fill('not-an-email')
  await screen.getByRole('button', { name: copy.contacts.form.create }).click()
  await expect
    .element(screen.getByRole('textbox', { name: copy.contacts.form.email }))
    .toHaveAccessibleDescription(copy.contacts.validation.emailInvalid)
})

test('a taken email comes back as a 422 and lands on the email field', async () => {
  const taken = CONTACT_FIXTURES[0]?.email ?? ''
  const { screen, currentPath } = await open('/acme/contacts/new')

  await screen.getByRole('textbox', { name: copy.contacts.form.name }).fill('Someone Else')
  await screen.getByRole('textbox', { name: copy.contacts.form.email }).fill(taken.toUpperCase())
  await screen.getByRole('button', { name: copy.contacts.form.create }).click()

  const email = screen.getByRole('textbox', { name: copy.contacts.form.email })
  await expect.element(email).toHaveAccessibleDescription('The email has already been taken.')
  await expect.element(email).toHaveAttribute('aria-invalid', 'true')
  expect(currentPath()).toBe('/acme/contacts/new')
})

test('a failure that is not about a field shows a banner with the request id', async () => {
  worker.use(
    http.post(apiUrl('/contacts'), () => problem(500, 'server_error', { title: 'Server error' }, '01JREQ')),
  )
  const { screen } = await open('/acme/contacts/new')

  await screen.getByRole('textbox', { name: copy.contacts.form.name }).fill('Nima Sherpa')
  await screen.getByRole('textbox', { name: copy.contacts.form.email }).fill('nima@example.test')
  await screen.getByRole('button', { name: copy.contacts.form.create }).click()

  await expect.element(screen.getByText(copy.contacts.form.failed)).toBeVisible()
  await expect.element(screen.getByText(fill(copy.states.error.requestId, { id: '01JREQ' }))).toBeVisible()
})

test('creates a contact with an organisation and a new tag, then opens it', async () => {
  let body: Record<string, unknown> | undefined
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'POST' && new URL(request.url).pathname === '/v1/contacts') {
      body = (await request.clone().json()) as Record<string, unknown>
    }
  })
  const { screen, currentPath } = await open('/acme/contacts/new')

  await screen.getByRole('textbox', { name: copy.contacts.form.name }).fill('Nima Sherpa')
  await screen.getByRole('textbox', { name: copy.contacts.form.email }).fill('nima@example.test')
  await screen.getByRole('combobox', { name: copy.contacts.form.organization }).fill('glo')
  await screen.getByRole('option', { name: 'Globex' }).click()
  const tags = screen.getByRole('combobox', { name: copy.contacts.form.tags })
  await tags.fill('Key account')
  await screen.getByRole('option', { name: fill(copy.combobox.addTag, { name: 'Key account' }) }).click()
  await userEvent.keyboard('{Escape}')
  await expect
    .element(screen.getByRole('button', { name: fill(copy.combobox.removeTag, { name: 'Key account' }) }))
    .toBeVisible()

  await screen.getByRole('button', { name: copy.contacts.form.create }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: 'Nima Sherpa' })).toBeVisible()
  expect(currentPath()).toMatch(/^\/acme\/contacts\/019a/)
  expect(body).toMatchObject({
    name: 'Nima Sherpa',
    email: 'nima@example.test',
    phone: null,
    organization_id: db.organizations[1]?.id,
    tags: ['Key account'],
  })
  await expect
    .element(screen.getByText(fill(copy.contacts.form.created, { name: 'Nima Sherpa' })))
    .toBeVisible()
  worker.events.removeAllListeners()
})

test('edits, archives and restores a contact', async () => {
  const contact = CONTACT_FIXTURES[1]
  const { screen } = await open(`/acme/contacts/${contact?.id}`)
  await expect.element(screen.getByRole('heading', { level: 1, name: contact?.name })).toBeVisible()

  const phone = screen.getByRole('textbox', { name: copy.contacts.form.phone })
  await phone.fill('+977 1 5550100')
  await screen.getByRole('button', { name: copy.contacts.form.save }).click()
  await expect.element(screen.getByText(copy.contacts.form.saved)).toBeVisible()
  expect(db.contacts.find((c) => c.id === contact?.id)?.phone).toBe('+977 1 5550100')

  await screen.getByRole('button', { name: copy.contacts.detail.archive }).click()
  await expect.element(screen.getByRole('button', { name: copy.contacts.detail.unarchive })).toBeVisible()
  await expect.element(screen.getByText(copy.contacts.list.archived, { exact: true })).toBeVisible()

  await screen.getByRole('button', { name: copy.contacts.detail.unarchive }).click()
  await expect.element(screen.getByRole('button', { name: copy.contacts.detail.archive })).toBeVisible()
})

test('the archived filter asks the API for archived contacts', async () => {
  const archived = db.contacts[0]
  if (archived) archived.archived_at = '2026-09-10T08:00:00Z'
  const requests: URL[] = []
  worker.events.on('request:start', ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname === '/v1/contacts') requests.push(url)
  })
  const { screen, currentLocation } = await open('/acme/contacts')
  const table = screen.getByRole('table', { name: copy.contacts.list.label })
  await expect.element(table.getByRole('row').nth(1)).toBeVisible()
  expect(table.getByText(archived?.name ?? '', { exact: true }).query()).toBeNull()

  await screen.getByRole('combobox', { name: copy.contacts.list.archivedFilter }).click()
  await screen.getByRole('option', { name: copy.contacts.list.archivedOptions.true }).click()

  await expect.element(table.getByText(new RegExp(archived?.name ?? ''))).toBeVisible()
  expect(currentLocation().search).toEqual({ archived: 'true' })
  expect(requests.at(-1)?.searchParams.get('filter[archived]')).toBe('true')
  worker.events.removeAllListeners()
})

test('without contacts.manage the contact is read-only and there is no "New contact"', async () => {
  const contact = CONTACT_FIXTURES[2]
  const { screen } = await open(`/acme/contacts/${contact?.id}`, ['contacts.view'])
  await expect.element(screen.getByRole('heading', { level: 1, name: contact?.name })).toBeVisible()

  await expect.element(screen.getByText(copy.contacts.detail.readOnly)).toBeVisible()
  expect(screen.getByRole('button', { name: copy.contacts.form.save }).query()).toBeNull()
  expect(screen.getByRole('button', { name: copy.contacts.detail.archive }).query()).toBeNull()

  await screen.getByRole('link', { name: copy.contacts.detail.back }).click()
  await expect.element(screen.getByRole('heading', { level: 1, name: copy.contacts.title })).toBeVisible()
  expect(screen.getByRole('link', { name: copy.contacts.list.create }).query()).toBeNull()
})

test('Enter on a contact row opens the contact', async () => {
  const { screen, currentPath } = await open('/acme/contacts')
  const table = screen.getByRole('table', { name: copy.contacts.list.label })
  const first = table.getByRole('row').nth(1)
  await expect.element(first).toMatchTextContent(/Aarav Adhikari/)
  ;(first.element() as HTMLElement).focus()
  await userEvent.keyboard('{Enter}')

  await expect.element(screen.getByRole('heading', { level: 1, name: 'Aarav Adhikari' })).toBeVisible()
  expect(currentPath()).toBe(`/acme/contacts/${CONTACT_FIXTURES[0]?.id}`)
})

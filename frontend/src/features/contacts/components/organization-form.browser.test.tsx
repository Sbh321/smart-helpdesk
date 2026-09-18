import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/** Roadmap M1-15: the organisation list and form. */
const worker = setupMswWorker()

async function open(path: string, permissions = ['contacts.view', 'contacts.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  return renderApp(path)
}

test('the organisation form checks the name and the domain before sending', async () => {
  const { screen } = await open('/acme/organizations/new')
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.organizations.newTitle }))
    .toBeVisible()

  await screen.getByRole('textbox', { name: copy.organizations.form.domain }).fill('https://acme.example/')
  await screen.getByRole('button', { name: copy.organizations.form.create }).click()

  await expect
    .element(screen.getByRole('textbox', { name: copy.organizations.form.name }))
    .toHaveAccessibleDescription(copy.organizations.validation.nameRequired)
  await expect
    .element(screen.getByRole('textbox', { name: copy.organizations.form.domain }))
    .toHaveAccessibleDescription(
      `${copy.organizations.form.domainDescription} ${copy.organizations.validation.domainInvalid}`,
    )
})

test('a 422 from the API lands on the named field', async () => {
  worker.use(
    http.post(apiUrl('/organizations'), () =>
      problem(422, 'validation_failed', { errors: { name: ['An organisation with this name exists.'] } }),
    ),
  )
  const { screen } = await open('/acme/organizations/new')

  await screen.getByRole('textbox', { name: copy.organizations.form.name }).fill('Globex')
  await screen.getByRole('button', { name: copy.organizations.form.create }).click()

  await expect
    .element(screen.getByRole('textbox', { name: copy.organizations.form.name }))
    .toHaveAccessibleDescription('An organisation with this name exists.')
})

test('creates an organisation with a tier and opens it', async () => {
  let body: Record<string, unknown> | undefined
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'POST' && new URL(request.url).pathname === '/v1/organizations') {
      body = (await request.clone().json()) as Record<string, unknown>
    }
  })
  const { screen, currentPath } = await open('/acme/organizations')
  await screen.getByRole('link', { name: copy.organizations.list.create }).click()

  await screen.getByRole('textbox', { name: copy.organizations.form.name }).fill('Umbrella')
  await screen.getByRole('textbox', { name: copy.organizations.form.domain }).fill('umbrella.example')
  await screen.getByRole('combobox', { name: copy.organizations.form.tier }).click()
  await screen.getByRole('option', { name: copy.organizations.tier.premium }).click()
  await screen.getByRole('button', { name: copy.organizations.form.create }).click()

  await expect.element(screen.getByRole('heading', { level: 1, name: 'Umbrella' })).toBeVisible()
  expect(currentPath()).toMatch(/^\/acme\/organizations\/019a/)
  expect(body).toMatchObject({ name: 'Umbrella', domain: 'umbrella.example', tier: 'premium', tags: [] })
  worker.events.removeAllListeners()
})

test('the organisation list filters by tier and sorts through the URL', async () => {
  const requests: URL[] = []
  worker.events.on('request:start', ({ request }) => {
    const url = new URL(request.url)
    if (url.pathname === '/v1/organizations' && url.searchParams.has('page')) requests.push(url)
  })
  const { screen, currentLocation } = await open('/acme/organizations')
  const nav = screen.getByRole('navigation', { name: copy.nav.primary })
  await expect.element(nav.getByRole('link', { name: copy.nav.organizations })).toBeVisible()
  const table = screen.getByRole('table', { name: copy.organizations.list.label })
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(/Acme Corporation/)

  await screen.getByRole('combobox', { name: copy.organizations.list.tierFilter }).click()
  await screen.getByRole('option', { name: copy.organizations.tier.premium }).click()
  await expect.element(table.getByRole('row').nth(1)).toMatchTextContent(/Globex/)
  expect(currentLocation().search).toEqual({ tier: 'premium' })
  expect(requests.at(-1)?.searchParams.get('filter[tier]')).toBe('premium')

  await table
    .getByRole('columnheader', { name: copy.organizations.columns.createdAt })
    .getByRole('button')
    .click()
  await expect.poll(() => currentLocation().search).toEqual({ tier: 'premium', sort: 'created_at' })
  worker.events.removeAllListeners()
})

test('without contacts.manage the list has no "New organisation" action', async () => {
  const { screen } = await open('/acme/organizations', ['contacts.view'])
  await expect
    .element(screen.getByRole('heading', { level: 1, name: copy.organizations.title }))
    .toBeVisible()
  expect(screen.getByRole('link', { name: copy.organizations.list.create }).query()).toBeNull()
})

import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { TENANT_ATTRIBUTE, TENANT_BRAND_STYLE_ID } from '@/lib/theme/theme-dom'
import { setupMswWorker } from '@/test/msw/browser'
import { db } from '@/test/msw/data'
import { apiUrl, problem, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.workspaceSettings
const rules = text.validation

function signedIn(permissions = ['tickets.view', 'settings.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

function brandStyle(): string | null {
  return document.getElementById(TENANT_BRAND_STYLE_ID)?.textContent ?? null
}

test('General saves the workspace name and shows a 422 on its field', async () => {
  signedIn()
  const bodies: unknown[] = []
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'PATCH' && request.url.endsWith('/settings/general'))
      bodies.push(await request.clone().json())
  })
  const { screen } = await renderApp('/acme/settings/general')

  const name = screen.getByRole('textbox', { name: text.generalForm.name })
  await expect.element(name).toHaveValue(String(db.settings.general?.name))
  await name.fill('A')
  await screen.getByRole('button', { name: text.save }).click()
  await expect.element(name).toHaveAccessibleDescription(rules.nameLength)
  expect(bodies).toEqual([])

  await name.fill('Acme Help Centre')
  await screen.getByRole('button', { name: text.save }).click()
  await expect.poll(() => db.settings.general?.name).toBe('Acme Help Centre')
  expect(bodies).toEqual([{ name: 'Acme Help Centre', timezone: db.settings.general?.timezone }])

  worker.use(
    http.patch(apiUrl('/settings/{section}'), () =>
      problem(422, 'validation_failed', { errors: { name: ['This name is already in use.'] } }),
    ),
  )
  // The success toast can cover the button in the narrow test viewport; Enter submits the same form.
  await name.fill('Acme Support')
  await userEvent.keyboard('{Enter}')
  await expect.element(name).toHaveAccessibleDescription('This name is already in use.')
})

test('Branding previews the colour at once and reverts it when the page is left unsaved', async () => {
  signedIn()
  const { screen, router } = await renderApp('/acme/settings/branding')
  expect(brandStyle()).toBeNull()

  await screen.getByRole('textbox', { name: text.brandingForm.hex }).fill('#0f766e')
  await expect.poll(brandStyle).toContain('--primary: #0f766e;')
  expect(document.documentElement.hasAttribute(TENANT_ATTRIBUTE)).toBe(true)
  await expect.element(screen.getByText(text.brandingForm.unsaved)).toBeVisible()

  await router.navigate({ to: '/$workspace/settings/general', params: { workspace: 'acme' } })
  await expect.poll(brandStyle).toBeNull()
  expect(document.documentElement.hasAttribute(TENANT_ATTRIBUTE)).toBe(false)
})

test('Branding blocks a colour without readable text and saves a readable one', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/settings/branding')
  const hex = screen.getByRole('textbox', { name: text.brandingForm.hex })

  await hex.fill('#777777')
  await expect.element(screen.getByText(/Neither light nor dark text reaches 4\.5:1/)).toBeVisible()
  await screen.getByRole('button', { name: text.save }).click()
  await expect.element(hex).toHaveAccessibleDescription(new RegExp(rules.contrast.replace(/[.:]/g, '\\$&')))
  expect(db.settings.branding?.primary).toBeNull()

  await hex.fill('#0F766E')
  await screen.getByRole('button', { name: text.save }).click()
  await expect.poll(() => db.settings.branding?.primary).toBe('#0f766e')

  await screen.getByRole('button', { name: text.brandingForm.resetColour }).click()
  await screen.getByRole('button', { name: text.save }).click()
  await expect.poll(() => db.settings.branding?.primary).toBeNull()
})

test('Branding uploads a logo to the Branding folder and saves its id', async () => {
  signedIn()
  const intents: unknown[] = []
  worker.events.on('request:start', async ({ request }) => {
    if (request.url.endsWith('/media/intent')) intents.push(await request.clone().json())
  })
  const { screen } = await renderApp('/acme/settings/branding')

  const file = new File([new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])], 'logo.png', {
    type: 'image/png',
  })
  await screen.getByLabelText('Logo file', { exact: true }).upload(file)
  await expect.element(screen.getByRole('button', { name: 'Remove logo', exact: true })).toBeVisible()
  expect(intents).toEqual([expect.objectContaining({ filename: 'logo.png', purpose: 'branding' })])

  await screen.getByRole('button', { name: text.save }).click()
  await expect.poll(() => db.settings.branding?.logo_media_id).toEqual(expect.any(String))

  const notImage = new File(['hello'], 'notes.txt', { type: 'text/plain' })
  await screen.getByLabelText('Logo for the dark theme file', { exact: true }).upload(notImage)
  await expect.element(screen.getByText(text.brandingForm.imagesOnly)).toBeVisible()
})

test('Priority saves weights and shows settings_invalid on the weights group', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/settings/priority')

  await screen.getByRole('spinbutton', { name: 'Impact' }).fill('0.5')
  await screen.getByRole('spinbutton', { name: 'Urgency' }).fill('0.25')
  await screen.getByRole('button', { name: copy.priority.saveSettings }).click()
  await expect
    .poll(() => db.settings['automation.priority'])
    .toMatchObject({
      baseline: { weights: { impact: 0.5, urgency: 0.25, tier: 0.15, age: 0.1 } },
    })

  worker.use(
    http.patch(apiUrl('/settings/{section}'), () =>
      problem(422, 'settings_invalid', {
        errors: { 'baseline.weights': ['The four weights must add up to 1.'] },
      }),
    ),
  )
  await screen.getByRole('button', { name: copy.priority.saveSettings }).click()
  await expect.element(screen.getByText('The four weights must add up to 1.')).toBeVisible()
})

test('Automation switches automatic assignment off, and back when the save fails', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/settings/automation')
  const toggle = screen.getByRole('switch', { name: text.automationForm.assignment })

  await expect.element(toggle).toBeChecked()
  await toggle.click()
  await expect.element(toggle).not.toBeChecked()
  await expect.poll(() => db.settings['automation.assignment']).toEqual({ enabled: false })

  worker.use(
    http.patch(apiUrl('/settings/{section}'), () => problem(500, 'internal_error', { detail: 'Try again.' })),
  )
  await toggle.click()
  await expect.element(screen.getByRole('alert')).toMatchTextContent(/could not be changed/)
  await expect.element(toggle).not.toBeChecked()
})

test('Tickets saves the reopen window and resets to the defaults', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/settings/tickets')
  const reopen = screen.getByRole('spinbutton', { name: text.ticketsForm.reopenWindowDays })

  await reopen.fill('3')
  await screen.getByRole('button', { name: text.save }).click()
  await expect.poll(() => db.settings.tickets).toEqual({ auto_close_days: 7, reopen_window_days: 3 })

  await screen.getByRole('button', { name: text.resetDefaults }).click()
  await expect.element(reopen).toHaveValue(14)
})

test('workspace settings need settings.manage', async () => {
  signedIn(['tickets.view'])
  const { screen } = await renderApp('/acme/settings/branding')
  await expect.element(screen.getByText(/permission/i)).toBeVisible()
})

test('the saved brand colour and logo reach the whole app from /me', async () => {
  const base = sessionFixture({ permissions: ['tickets.view'] })
  const logo = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="20"/%3E'
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({
        data: {
          ...base,
          tenant: { ...base.tenant, branding: { primary: '#0f766e', logo_url: logo, logo_dark_url: null } },
        },
      }),
    ),
  )
  const { screen } = await renderApp('/acme')

  await expect.poll(brandStyle).toContain('--primary: #0f766e;')
  expect(document.documentElement.hasAttribute(TENANT_ATTRIBUTE)).toBe(true)
  const img = screen.container.querySelector<HTMLImageElement>('[data-slot="tenant-logo"]')
  expect(img?.getAttribute('src')).toBe(logo)
  expect(img?.getAttribute('alt')).toBe('')
})

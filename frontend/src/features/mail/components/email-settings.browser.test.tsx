import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { emailSettingsFixture, MAIL_DKIM_KEY } from '@/test/msw/mail'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.mailSettings
const inbound = copy.mailSettings.inbound

function signedIn(permissions = ['tickets.view', 'settings.manage', 'mail.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
}

test('Email shows the sender, the addresses and the DNS records, and saves a sender name', async () => {
  signedIn()
  const bodies: unknown[] = []
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'PATCH' && request.url.endsWith('/settings/email'))
      bodies.push(await request.clone().json())
  })
  const { screen } = await renderApp('/acme/settings/email')

  await expect.element(screen.getByRole('heading', { level: 2, name: text.title })).toBeVisible()
  await expect.element(screen.getByRole('link', { name: text.nav })).toHaveAttribute('aria-current', 'page')
  const name = screen.getByRole('textbox', { name: text.senderName })
  await expect.element(name).toHaveValue('')
  await expect
    .element(name)
    .toHaveAccessibleDescription(fill(text.senderNameHelp, { default: 'Acme Support' }))
  await expect
    .element(screen.getByTestId('sender-preview'))
    .toHaveTextContent('Acme Support <support+acme@shp.localhost>')
  await expect.element(screen.getByText('support+acme@shp.localhost', { exact: true })).toBeVisible()
  await expect.element(screen.getByText('ticket+<ticket-id>@shp.localhost')).toBeVisible()
  await expect.element(screen.getByText('Acme via Smart Helpdesk <no-reply@shp.localhost>')).toBeVisible()
  await expect.element(screen.getByText('v1-rsa-20260921._domainkey.shp.localhost')).toBeVisible()
  await expect.element(screen.getByText(`v=DKIM1; k=rsa; h=sha256; p=${MAIL_DKIM_KEY}`)).toBeVisible()
  await expect
    .element(screen.getByRole('button', { name: fill(text.copyNamed, { name: text.intakeAddress }) }))
    .toBeVisible()

  // Client rules first: an address as a name is refused before any request.
  await name.fill('support@paypal.com')
  await screen.getByRole('button', { name: text.save }).click()
  await expect.element(name).toHaveAccessibleDescription(new RegExp(text.validation.senderNameCharacters))
  expect(bodies).toEqual([])

  await name.fill('Acme Customer Care')
  await expect
    .element(screen.getByTestId('sender-preview'))
    .toHaveTextContent('Acme Customer Care <support+acme@shp.localhost>')
  await userEvent.keyboard('{Enter}')
  await expect.poll(() => bodies).toEqual([{ sender_name: 'Acme Customer Care' }])

  // Emptying the field restores the default: the API gets null.
  await name.fill('')
  await userEvent.keyboard('{Enter}')
  await expect.poll(() => bodies).toEqual([{ sender_name: 'Acme Customer Care' }, { sender_name: null }])
})

test('Email marks a DNS record that is not set up yet', async () => {
  signedIn()
  worker.use(
    http.get(apiUrl('/settings/email'), () => {
      const settings = emailSettingsFixture()
      settings.dns_records[2] = {
        type: 'TXT',
        purpose: 'DKIM: the public key that verifies the signature on every outgoing mail.',
        name: '<selector>._domainkey.shp.localhost',
        value: 'Run infra/scripts/mail-init.sh and set MAIL_DKIM_SELECTOR and MAIL_DKIM_PUBLIC_KEY.',
        ready: false,
      }
      return HttpResponse.json({ data: settings })
    }),
  )
  const { screen } = await renderApp('/acme/settings/email')

  await expect.element(screen.getByText(text.dnsNotReady)).toBeVisible()
  await expect.element(screen.getByText(/Run infra\/scripts\/mail-init\.sh/)).toBeVisible()
})

test('Email is hidden from the navigation and forbidden without mail.manage', async () => {
  signedIn(['tickets.view', 'settings.manage'])
  const { screen } = await renderApp('/acme/settings/email')

  await expect.element(screen.getByText(copy.states.forbidden.title)).toBeVisible()
  expect(screen.getByRole('link', { name: text.nav }).elements()).toHaveLength(0)
})

test('Email lists received mail with its result, reason and ticket, filtered by result in the URL', async () => {
  signedIn()
  const { screen, currentLocation } = await renderApp('/acme/settings/email')

  const table = screen.getByRole('table', { name: inbound.tableLabel })
  await expect.element(table.getByText('Close this')).toBeVisible()
  await expect.element(table.getByText(inbound.reasons.sender_not_allowed ?? '')).toBeVisible()
  await expect.element(table.getByText(inbound.reasons.auto_reply ?? '')).toBeVisible()
  await expect.element(table.getByText(inbound.attachmentsOne)).toBeVisible()
  await expect.element(table.getByText(inbound.noSubject)).toBeVisible()
  await expect.element(table.getByText('ravi@umbrella.test')).toBeVisible()
  await expect
    .element(table.getByRole('link', { name: '#1009 Invoice copy' }))
    .toHaveAttribute('href', '/acme/tickets/01990000-0000-7000-8000-000000000109')

  await screen.getByRole('combobox', { name: inbound.filterState }).click()
  await screen.getByRole('option', { name: inbound.states.rejected ?? '' }).click()
  await userEvent.keyboard('{Escape}')
  await expect.poll(() => currentLocation().search).toMatchObject({ state: 'rejected' })
  await expect.element(table.getByText('Close this')).toBeVisible()
  await expect.element(table.getByText('Invoice copy')).not.toBeInTheDocument()
})

test('Email switches the unknown-sender rules and keeps the sender name', async () => {
  signedIn()
  const bodies: unknown[] = []
  worker.events.on('request:start', async ({ request }) => {
    if (request.method === 'PATCH' && request.url.endsWith('/settings/email'))
      bodies.push(await request.clone().json())
  })
  const { screen } = await renderApp('/acme/settings/email')

  const create = screen.getByRole('switch', { name: inbound.createContacts })
  await expect.element(create).toBeChecked()
  await expect.element(create).toHaveAccessibleDescription(inbound.createContactsHelp)
  await create.click()
  await expect.poll(() => bodies).toEqual([{ create_contacts: false }])
  await expect.element(create).not.toBeChecked()
  await expect.element(screen.getByRole('switch', { name: inbound.matchOrganisation })).toBeChecked()
})

test('the Email settings page has no serious or critical axe violations', async () => {
  signedIn()
  const { screen } = await renderApp('/acme/settings/email')
  await expect.element(screen.getByText('v1-rsa-20260921._domainkey.shp.localhost')).toBeVisible()
  await expect.element(screen.getByText('Close this')).toBeVisible()
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))

  const results = await axe.run(screen.container, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
  })
  const blocking = results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
  expect(blocking).toEqual([])
})

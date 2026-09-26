import axe from 'axe-core'
import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { copy, fill } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

/** The dashboard's Get started panel: the support address and the setup pages, for owners and admins. */
const worker = setupMswWorker()
const OWNER = [
  'tickets.view',
  'reports.view',
  'mail.manage',
  'settings.manage',
  'users.manage',
  'agents.view',
  'integrations.manage',
]

function signIn(permissions: string[]) {
  const session = sessionFixture({ permissions })
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: session })))
  return session
}

async function blocking(): Promise<string[]> {
  await Promise.all(document.getAnimations().map((animation) => animation.finished.catch(() => undefined)))
  const results = await axe.run(document.body)
  return results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
}

test('an owner sees the support address and the setup steps, and can hide the panel', async () => {
  window.localStorage.clear()
  const session = signIn(OWNER)
  const { screen, currentPath } = await renderApp('/acme')
  const panel = screen.getByRole('region', {
    name: fill(copy.getStarted.title, { name: session.tenant.name }),
  })
  await expect
    .element(panel.getByRole('textbox', { name: copy.getStarted.emailLabel }))
    .toHaveValue('support+acme@shp.localhost')
  await expect.element(panel.getByRole('link', { name: /Invite your team/ })).toBeVisible()
  await expect.element(panel.getByRole('link', { name: /Connect other systems/ })).toBeVisible()
  expect(await blocking()).toEqual([])

  await panel.getByRole('link', { name: /SLA targets and business hours/ }).click()
  await expect.poll(() => currentPath()).toBe('/acme/settings/sla')
})

test('hiding the panel is remembered', async () => {
  window.localStorage.clear()
  const session = signIn(OWNER)
  const title = fill(copy.getStarted.title, { name: session.tenant.name })
  const first = await renderApp('/acme')
  await first.screen.getByRole('button', { name: copy.getStarted.hide }).click()
  expect(first.screen.getByRole('region', { name: title }).query()).toBeNull()

  const again = await renderApp('/acme')
  await expect.element(again.screen.getByRole('main')).toBeVisible()
  expect(again.screen.getByRole('region', { name: title }).query()).toBeNull()
})

test('an agent does not see it, and steps follow the permissions', async () => {
  window.localStorage.clear()
  const session = signIn(['tickets.view', 'reports.view'])
  const { screen } = await renderApp('/acme')
  await expect.element(screen.getByRole('main')).toBeVisible()
  expect(
    screen.getByRole('region', { name: fill(copy.getStarted.title, { name: session.tenant.name }) }).query(),
  ).toBeNull()
})

test('an admin without integrations sees the other steps only', async () => {
  window.localStorage.clear()
  const session = signIn(['tickets.view', 'reports.view', 'mail.manage', 'users.manage'])
  const { screen } = await renderApp('/acme')
  const panel = screen.getByRole('region', {
    name: fill(copy.getStarted.title, { name: session.tenant.name }),
  })
  await expect.element(panel.getByRole('link', { name: /Invite your team/ })).toBeVisible()
  expect(panel.getByRole('link', { name: /Connect other systems/ }).query()).toBeNull()
  expect(panel.getByRole('link', { name: /Logo and colour/ }).query()).toBeNull()
})

import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db, SESSION_USER_ID } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.roles

async function openRoles(permissions = ['tickets.view', 'settings.manage', 'users.manage', 'roles.manage']) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp('/acme/settings/roles')
  await expect.element(app.screen.getByRole('heading', { level: 4, name: 'Owner' })).toBeVisible()
  return app
}

function roleNamed(name: string) {
  return db.roles.find((role) => role.name === name)
}

test('default roles are listed read-only with their permissions', async () => {
  const { screen } = await openRoles()
  const owner = screen.getByRole('listitem', { name: 'Owner' })
  await expect.element(owner.getByText(text.readOnly)).toBeVisible()
  await expect.element(owner.getByText('Assign tickets')).toBeVisible()
  expect(owner.getByRole('button').query()).toBeNull()
  expect(screen.getByRole('button', { name: 'Edit agent' }).query()).toBeNull()
  await expect.element(screen.getByRole('button', { name: 'Edit billing-lead' })).toBeVisible()
})

test('a custom role is created from permissions grouped by module', async () => {
  const { screen } = await openRoles()
  await screen.getByRole('button', { name: text.create }).click()
  const dialog = screen.getByRole('dialog', { name: text.createTitle })
  const tickets = dialog.getByRole('group', { name: 'Tickets', exact: true })
  await expect.element(tickets).toBeVisible()

  await dialog.getByRole('button', { name: text.save }).click()
  await expect.element(dialog.getByText(text.validation.permissions)).toBeVisible()
  await expect
    .element(dialog.getByRole('textbox', { name: text.name }))
    .toHaveAttribute('aria-invalid', 'true')

  await dialog.getByRole('textbox', { name: text.name }).fill('escalations')
  const all = tickets.getByRole('checkbox', { name: 'All Tickets permissions' })
  await all.click()
  await expect.element(tickets.getByRole('checkbox', { name: 'Delete tickets' })).toBeChecked()
  await tickets.getByRole('checkbox', { name: 'Delete tickets' }).click()
  await expect.element(all).toHaveAttribute('aria-checked', 'mixed')
  await dialog.getByRole('checkbox', { name: 'Write internal notes' }).click()
  await dialog.getByRole('button', { name: text.save }).click()

  await expect
    .poll(() => roleNamed('escalations')?.permissions)
    .toEqual([
      'tickets.view',
      'tickets.create',
      'tickets.update',
      'tickets.assign',
      'tickets.resolve',
      'tickets.close',
      'tickets.reopen',
      'comments.internal',
    ])
  await expect.element(screen.getByRole('listitem', { name: 'escalations' })).toBeVisible()
})

test('a custom role is edited, and a permission beyond reach is refused beside the permissions', async () => {
  const { screen } = await openRoles()
  await screen.getByRole('button', { name: 'Edit night-shift' }).click()
  const dialog = screen.getByRole('dialog', { name: 'Edit night-shift' })
  await expect.element(dialog.getByRole('checkbox', { name: 'View tickets' })).toBeChecked()
  await dialog.getByRole('checkbox', { name: 'View media' }).click()
  await dialog.getByRole('button', { name: text.save }).click()
  await expect.poll(() => roleNamed('night-shift')?.permissions).toEqual(['tickets.view', 'media.view'])
  await expect.element(dialog).not.toBeInTheDocument()

  const self = db.users.find((user) => user.id === SESSION_USER_ID)
  if (self) self.roles = ['manager']
  await screen.getByRole('button', { name: 'Edit night-shift' }).click()
  const again = screen.getByRole('dialog', { name: 'Edit night-shift' })
  await again.getByRole('checkbox', { name: 'Manage users' }).click()
  // The success toast can cover the button in the narrow test viewport; Enter submits the same form.
  await again.getByRole('textbox', { name: text.name }).click()
  await userEvent.keyboard('{Enter}')
  await expect
    .element(again.getByText('You can only add or remove permissions you hold yourself. (Manage users)'))
    .toBeVisible()
  expect(roleNamed('night-shift')?.permissions).toEqual(['tickets.view', 'media.view'])
})

test('a role still held by users is not deleted; an unused one is', async () => {
  const { screen } = await openRoles()
  await screen.getByRole('button', { name: 'Delete billing-lead' }).click()
  const confirm = screen.getByRole('alertdialog', { name: text.deleteTitle })
  await confirm.getByRole('button', { name: text.deleteAction }).click()
  await expect.element(confirm.getByRole('alert')).toMatchTextContent(/1 user still holds this role/)
  expect(roleNamed('billing-lead')).toBeDefined()
  await confirm.getByRole('button', { name: copy.confirm.cancel }).click()

  await screen.getByRole('button', { name: 'Delete night-shift' }).click()
  await screen.getByRole('alertdialog').getByRole('button', { name: text.deleteAction }).click()
  await expect.poll(() => roleNamed('night-shift')).toBeUndefined()
  await expect.element(screen.getByRole('listitem', { name: 'night-shift' })).not.toBeInTheDocument()
})

import { HttpResponse, http } from 'msw'
import { expect, test } from 'vitest'
import { type Locator, userEvent } from 'vitest/browser'
import { copy } from '@/copy/en'
import { setupMswWorker } from '@/test/msw/browser'
import { db, SESSION_USER_ID } from '@/test/msw/data'
import { apiUrl, sessionFixture } from '@/test/msw/handlers'
import { renderApp } from '@/test/render-app'

const worker = setupMswWorker()
const text = copy.users
const ADMIN = ['tickets.view', 'settings.manage', 'users.manage', 'roles.manage']

async function openUsers(permissions = ADMIN) {
  worker.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture({ permissions }) })))
  const app = await renderApp('/acme/settings/users')
  const table = app.screen.getByRole('table', { name: text.tableLabel })
  await expect.element(table.getByText('Asha Rai')).toBeVisible()
  return { ...app, table }
}

function row(table: Locator, name: string) {
  return table.getByRole('row').filter({ hasText: name })
}

function userNamed(name: string) {
  return db.users.find((user) => user.name === name)
}

test('the list shows status, roles and sign-in, and filters by status in the URL', async () => {
  const { screen, table, currentLocation } = await openUsers()
  await expect.element(row(table, 'Kiran Thapa').getByText(text.invitationExpired)).toBeVisible()
  await expect.element(row(table, 'Nima Lama').getByText(text.statuses.invited)).toBeVisible()
  await expect.element(row(table, 'Dipesh Karki').getByText(text.statuses.disabled)).toBeVisible()
  await expect.element(row(table, 'Asha Rai').getByText('billing-lead')).toBeVisible()
  // 06:30 UTC is 12:15 in the workspace time zone (Asia/Kathmandu).
  await expect.element(row(table, 'Asha Rai').getByText('12 Sep 2026, 12:15')).toBeVisible()

  await screen.getByRole('combobox', { name: text.statusFilter }).click()
  await screen.getByRole('option', { name: text.statuses.invited }).click()
  await userEvent.keyboard('{Escape}')
  await expect.poll(() => currentLocation().search).toMatchObject({ status: 'invited' })
  await expect.element(table.getByText('Asha Rai')).not.toBeInTheDocument()
  await expect.element(table.getByText('Nima Lama')).toBeVisible()
})

test('an invited user appears as Invited until they accept', async () => {
  const { screen, table } = await openUsers()
  await screen.getByRole('button', { name: text.invite }).click()
  const dialog = screen.getByRole('dialog', { name: text.inviteTitle })
  await dialog.getByRole('button', { name: text.sendInvitation }).click()
  await expect
    .element(dialog.getByRole('textbox', { name: text.name }))
    .toHaveAttribute('aria-invalid', 'true')
  await expect
    .element(dialog.getByRole('group', { name: text.roles }).getByRole('alert'))
    .toHaveTextContent(text.validation.roles)

  await dialog.getByRole('textbox', { name: text.name }).fill('Sita Gurung')
  await dialog.getByRole('textbox', { name: text.email }).fill('sita@acme.test')
  await dialog.getByRole('checkbox', { name: 'Agent' }).click()
  await dialog.getByRole('button', { name: text.sendInvitation }).click()

  await expect.poll(() => userNamed('Sita Gurung')).toMatchObject({ status: 'invited', roles: ['agent'] })
  await expect.element(dialog).not.toBeInTheDocument()
  await expect.element(row(table, 'Sita Gurung').getByText(text.statuses.invited)).toBeVisible()
})

test('a taken email is shown on the email field', async () => {
  const { screen } = await openUsers()
  await screen.getByRole('button', { name: text.invite }).click()
  const dialog = screen.getByRole('dialog', { name: text.inviteTitle })
  await dialog.getByRole('textbox', { name: text.name }).fill('Asha Again')
  const email = dialog.getByRole('textbox', { name: text.email })
  await email.fill('asha@acme.test')
  await dialog.getByRole('checkbox', { name: 'Agent' }).click()
  await dialog.getByRole('button', { name: text.sendInvitation }).click()
  await expect
    .element(email)
    .toHaveAccessibleDescription('A user with this email address is already in the workspace.')
  expect(db.users.filter((user) => user.email === 'asha@acme.test')).toHaveLength(1)
})

test('only an owner can give the owner role: the refusal sits beside the roles', async () => {
  const self = db.users.find((user) => user.id === SESSION_USER_ID)
  if (self) self.roles = ['admin']
  const { screen } = await openUsers()
  await screen.getByRole('button', { name: text.invite }).click()
  const dialog = screen.getByRole('dialog', { name: text.inviteTitle })
  await dialog.getByRole('textbox', { name: text.name }).fill('New Owner')
  await dialog.getByRole('textbox', { name: text.email }).fill('owner2@acme.test')
  await dialog.getByRole('checkbox', { name: 'Owner' }).click()
  await dialog.getByRole('button', { name: text.sendInvitation }).click()
  const roles = dialog.getByRole('group', { name: text.roles })
  await expect
    .element(roles.getByRole('alert'))
    .toHaveTextContent('Only an owner can give or take the owner role. (Owner)')
  expect(userNamed('New Owner')).toBeUndefined()
})

test('editing roles: a custom role can be given, and the last owner keeps the owner role', async () => {
  const { screen, table } = await openUsers()
  await screen.getByRole('button', { name: 'Edit Bikram Shah' }).click()
  const edit = screen.getByRole('dialog', { name: 'Edit Bikram Shah' })
  await edit.getByRole('checkbox', { name: 'billing-lead' }).click()
  await edit.getByRole('button', { name: text.save }).click()
  await expect.poll(() => userNamed('Bikram Shah')?.roles).toEqual(['admin', 'manager', 'billing-lead'])
  await expect.element(edit).not.toBeInTheDocument()
  await expect.element(row(table, 'Bikram Shah').getByText('billing-lead')).toBeVisible()

  await screen.getByRole('button', { name: 'Edit Priya Sharma' }).click()
  const self = screen.getByRole('dialog', { name: 'Edit Priya Sharma' })
  await self.getByRole('checkbox', { name: 'Admin' }).click()
  await self.getByRole('checkbox', { name: 'Owner' }).click()
  // The success toast can cover the button in the narrow test viewport; Enter submits the same form.
  await self.getByRole('textbox', { name: text.name }).click()
  await userEvent.keyboard('{Enter}')
  await expect.element(self.getByRole('alert')).toMatchTextContent(/at least one active owner/)
  expect(db.users.find((user) => user.id === SESSION_USER_ID)?.roles).toEqual(['owner'])
})

test('an invitation is resent with a new link', async () => {
  const { screen, table } = await openUsers()
  await expect.element(row(table, 'Kiran Thapa').getByText(text.invitationExpired)).toBeVisible()
  expect(screen.getByRole('button', { name: 'Resend invitation to Asha Rai' }).query()).toBeNull()
  await screen.getByRole('button', { name: 'Resend invitation to Kiran Thapa' }).click()
  await expect.poll(() => userNamed('Kiran Thapa')?.invitation_expired).toBe(false)
  await expect.element(row(table, 'Kiran Thapa').getByText(text.statuses.invited)).toBeVisible()
})

test('disabling asks first; a disabled user can be enabled again', async () => {
  const { screen } = await openUsers()
  await screen.getByRole('button', { name: 'Disable Asha Rai' }).click()
  const confirm = screen.getByRole('alertdialog', { name: text.disableTitle })
  await expect.element(confirm.getByRole('button', { name: copy.confirm.cancel })).toHaveFocus()
  await confirm.getByRole('button', { name: copy.confirm.cancel }).click()
  expect(userNamed('Asha Rai')?.status).toBe('active')

  await screen.getByRole('button', { name: 'Disable Asha Rai' }).click()
  await screen.getByRole('alertdialog').getByRole('button', { name: text.disable }).click()
  await expect.poll(() => userNamed('Asha Rai')?.status).toBe('disabled')

  await screen.getByRole('button', { name: 'Enable Asha Rai' }).click()
  await expect.poll(() => userNamed('Asha Rai')?.status).toBe('active')
  await expect.element(screen.getByRole('button', { name: 'Disable Asha Rai' })).toBeVisible()
})

test('the signed-in user cannot disable their own account', async () => {
  const { screen } = await openUsers()
  await expect.element(screen.getByRole('button', { name: 'Edit Priya Sharma' })).toBeVisible()
  expect(screen.getByRole('button', { name: 'Disable Priya Sharma' }).query()).toBeNull()
})

test('without roles.manage the role picker offers the five default roles', async () => {
  const { screen } = await openUsers(['users.manage'])
  await screen.getByRole('button', { name: text.invite }).click()
  const roles = screen
    .getByRole('dialog', { name: text.inviteTitle })
    .getByRole('group', { name: text.roles })
  await expect.element(roles.getByRole('checkbox', { name: 'Developer' })).toBeVisible()
  expect(roles.getByRole('checkbox').all()).toHaveLength(5)
})

test('without users.manage the page is forbidden', async () => {
  worker.use(
    http.get(apiUrl('/me'), () =>
      HttpResponse.json({ data: sessionFixture({ permissions: ['tickets.view'] }) }),
    ),
  )
  const { screen } = await renderApp('/acme/settings/users')
  await expect.element(screen.getByRole('navigation', { name: copy.settings.sections })).toBeVisible()
  expect(screen.getByRole('link', { name: text.title }).query()).toBeNull()
  expect(screen.getByRole('table', { name: text.tableLabel }).query()).toBeNull()
})

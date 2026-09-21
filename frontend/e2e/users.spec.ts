import { expect, type Page, test } from '@playwright/test'

/**
 * M2-12 through the UI against the Compose stack: an invited user stays pending until they accept the
 * mailed link, then signs in with the role they were given. Needs the dev seed and Mailpit on the
 * `mail` host.
 */

const workspace = process.env.E2E_WORKSPACE ?? 'acme'
const mailpit = process.env.E2E_MAILPIT ?? 'https://mail.shp.localhost'

async function signIn(page: Page, email: string, password: string) {
  await page.goto(`/${workspace}/login`)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(new RegExp(`/${workspace}/?$`))
}

test('an invited user is pending until they accept, then works with the invited role', async ({
  page,
  request,
}) => {
  const stamp = Date.now().toString(36)
  const email = `e2e-${stamp}@acme.test`
  await signIn(page, process.env.E2E_EMAIL ?? 'priya@acme.test', process.env.E2E_PASSWORD ?? 'password')

  await page.goto(`/${workspace}/settings/users`)
  await page.getByRole('button', { name: 'Invite user' }).click()
  const dialog = page.getByRole('dialog', { name: 'Invite a user' })
  await dialog.getByLabel('Name').fill(`E2E ${stamp}`)
  await dialog.getByLabel('Email').fill(email)
  await dialog.getByRole('checkbox', { name: 'Agent', exact: true }).check()
  await dialog.getByRole('button', { name: 'Send invitation' }).click()
  await expect(dialog).toBeHidden()

  await page.getByRole('searchbox').first().fill(stamp)
  const row = page.getByRole('row', { name: new RegExp(email) })
  await expect(row).toContainText('Invited')

  // The invitation mail carries the accept link.
  let link: string | undefined
  await expect(async () => {
    const search = await request.get(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`, {
      ignoreHTTPSErrors: true,
    })
    const id = (await search.json()).messages?.[0]?.ID
    expect(id).toBeTruthy()
    const message = await (
      await request.get(`${mailpit}/api/v1/message/${id}`, { ignoreHTTPSErrors: true })
    ).json()
    link = String(message.Text).match(/https:\/\/\S+accept-invitation\?token=\S+/)?.[0]
    expect(link).toBeTruthy()
  }).toPass({ timeout: 20_000 })

  const invited = await page.context().browser()?.newContext({ ignoreHTTPSErrors: true })
  const guest = await invited?.newPage()
  if (!guest || !link) throw new Error('no browser context')
  await guest.goto(link)
  await guest.getByLabel('Password', { exact: true }).fill('a-long-password-1')
  await guest.getByLabel('Confirm password').fill('a-long-password-1')
  await guest.getByRole('button', { name: 'Create my account' }).click()
  await expect(guest).toHaveURL(new RegExp(`/${workspace}/?$`))
  await expect(guest.getByRole('link', { name: 'Tickets' })).toBeVisible()
  await expect(guest.getByRole('link', { name: 'Settings' })).toHaveCount(0)

  await page.reload()
  await page.getByRole('searchbox').first().fill(stamp)
  await expect(page.getByRole('row', { name: new RegExp(email) })).toContainText('Active')
})

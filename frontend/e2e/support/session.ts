import { type APIResponse, type BrowserContext, expect, type Page } from '@playwright/test'
import { apiUrl, appUrl, password, type UserKey, users } from './env'

/** Signs in through the login form and waits for the dashboard. */
export async function signIn(page: Page, user: UserKey) {
  const { workspace, email } = users[user]
  await page.goto(`/${workspace}/login`)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  // Explicit: the global setup runs outside the test runner's expect timeout.
  await expect(page).toHaveURL(new RegExp(`/${workspace}/?$`), { timeout: 15_000 })
}

type Body = Record<string, unknown>

/**
 * The `/v1` API with the browser session of a context, as the SPA calls it: the session cookie, the
 * XSRF header and the app origin (Sanctum stateful requests). Used for set-up and for assertions that
 * the UI cannot show, never instead of the step under test.
 */
export function sessionApi(context: BrowserContext) {
  async function headers() {
    const xsrf = (await context.cookies(apiUrl)).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? ''
    return {
      Accept: 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(xsrf),
      Origin: new URL(appUrl).origin,
      Referer: `${new URL(appUrl).origin}/`,
    }
  }
  const call = async (method: 'get' | 'post' | 'patch' | 'delete', path: string, data?: Body) =>
    context.request[method](`${apiUrl}/v1${path}`, { headers: await headers(), data })

  return {
    get: (path: string) => call('get', path),
    post: (path: string, data?: Body) => call('post', path, data),
    patch: (path: string, data?: Body) => call('patch', path, data),
    /** GET and parse, failing the test with the response text when it is not a 2xx. */
    async json<T = { data: unknown }>(path: string): Promise<T> {
      const response = await call('get', path)
      await expectOk(response)
      return (await response.json()) as T
    },
  }
}

export async function expectOk(response: APIResponse) {
  expect(response.ok(), `${response.status()} ${response.url()}\n${await response.text()}`).toBeTruthy()
}

type Named = { id: string; name?: string }

/** The first item of a list the test needs, failing with a readable message when it is empty. */
export function first<T>(items: readonly T[], what: string): T {
  const item = items[0]
  if (item === undefined) throw new Error(`expected ${what}, found none`)
  return item
}

/** A contact and a category of the signed-in workspace, for tickets created in set-up. */
export async function ticketDefaults(api: ReturnType<typeof sessionApi>) {
  const contact = first((await api.json<{ data: Named[] }>('/contacts?per_page=1')).data, 'a contact')
  const category = first((await api.json<{ data: Named[] }>('/categories')).data, 'a category')
  return { contact_id: contact.id, category_id: category.id }
}

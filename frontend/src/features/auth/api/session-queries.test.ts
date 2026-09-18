import { QueryClient } from '@tanstack/react-query'
import { HttpResponse, http } from 'msw'
import { beforeEach, describe, expect, it } from 'vitest'
import { initApiClient } from '@/lib/api/client'
import { apiUrl, problem, sessionFixture, TEST_API_ORIGIN } from '@/test/msw/handlers'
import { setupMswServer } from '@/test/msw/node'
import { clearSession, ensureSession, reloadSession } from './session-queries'

const server = setupMswServer()

function queryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

beforeEach(() => {
  initApiClient({ apiBaseUrl: TEST_API_ORIGIN })
})

describe('the session query', () => {
  it('is null when the API says nobody is signed in', async () => {
    await expect(ensureSession(queryClient())).resolves.toBeNull()
  })

  it('is the /v1/me payload when a session exists', async () => {
    server.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture() })))

    const session = await ensureSession(queryClient())

    expect(session?.tenant.slug).toBe('acme')
    expect(session?.permissions).toEqual([])
  })

  it('asks the API once and serves the cache afterwards', async () => {
    let calls = 0
    server.use(
      http.get(apiUrl('/me'), () => {
        calls += 1
        return HttpResponse.json({ data: sessionFixture() })
      }),
    )
    const client = queryClient()

    await ensureSession(client)
    await ensureSession(client)

    expect(calls).toBe(1)
  })

  it('reports a real failure instead of pretending to be anonymous', async () => {
    server.use(http.get(apiUrl('/me'), () => problem(500, 'server_error')))

    await expect(ensureSession(queryClient())).rejects.toMatchObject({ status: 500 })
  })

  it('re-reads the session after sign-in', async () => {
    const client = queryClient()
    await ensureSession(client)

    server.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture() })))
    const session = await reloadSession(client)

    expect(session?.user.email).toBe('priya@acme.test')
  })

  it('forgets the session and every tenant query on sign-out', async () => {
    server.use(http.get(apiUrl('/me'), () => HttpResponse.json({ data: sessionFixture() })))
    const client = queryClient()
    await ensureSession(client)
    client.setQueryData(['tenant-id', 'tickets', 'list'], ['a ticket'])

    clearSession(client)

    expect(client.getQueryData(['session', 'me'])).toBeNull()
    expect(client.getQueryData(['tenant-id', 'tickets', 'list'])).toBeUndefined()
  })
})

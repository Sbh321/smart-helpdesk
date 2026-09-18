import { HttpResponse, http } from 'msw'
import { describe, expect, it } from 'vitest'
import { apiUrl, problem, TEST_API_ORIGIN } from '@/test/msw/handlers'
import { setupMswServer } from '@/test/msw/node'
import { apiBaseUrl, createApiClient, readCookie, unwrap, unwrapBody, XSRF_HEADER } from './client'
import { ApiError, toApiError } from './errors'

const server = setupMswServer()

const client = (cookies = '') => createApiClient({ apiBaseUrl: TEST_API_ORIGIN, readCookies: () => cookies })

async function caught(promise: Promise<unknown>): Promise<ApiError> {
  const error = await promise.then(
    () => undefined,
    (e: unknown) => e,
  )
  expect(error).toBeInstanceOf(ApiError)
  return error as ApiError
}

describe('api client', () => {
  it('unwraps the data envelope of a typed response', async () => {
    await expect(unwrap(client().GET('/ping'))).resolves.toEqual({ status: 'ok' })
  })

  it('sends JSON accept and credentials on every request', async () => {
    let seen: Request | undefined
    server.use(
      http.get(apiUrl('/ping'), ({ request }) => {
        seen = request
        return HttpResponse.json({ data: { status: 'ok' } })
      }),
    )
    await unwrap(client().GET('/ping'))
    expect(seen?.headers.get('Accept')).toBe('application/json')
    expect(seen?.credentials).toBe('include')
  })

  it('maps a 422 to field errors', async () => {
    server.use(
      http.get(apiUrl('/ping'), () =>
        problem(422, 'validation_failed', {
          detail: 'One or more fields are invalid.',
          errors: { email: ['The email field is required.'] },
        }),
      ),
    )
    const error = await caught(unwrap(client().GET('/ping')))
    expect(error).toMatchObject({
      status: 422,
      code: 'validation_failed',
      detail: 'One or more fields are invalid.',
      fieldErrors: { email: ['The email field is required.'] },
    })
    expect(error.isValidation).toBe(true)
  })

  it('keeps the request id of a 500 and its domain meta', async () => {
    server.use(
      http.get(apiUrl('/ping'), () =>
        problem(500, 'internal_error', { meta: { retry: false } }, '01JREQUEST'),
      ),
    )
    const error = await caught(unwrap(client().GET('/ping')))
    expect(error).toMatchObject({ status: 500, code: 'internal_error', requestId: '01JREQUEST' })
    expect(error.meta).toEqual({ retry: false })
    expect(error.detail).toBeUndefined()
  })

  it('maps a non-JSON error body to unexpected_response with the header request id', async () => {
    server.use(
      http.get(
        apiUrl('/ping'),
        () =>
          new HttpResponse('<html>Bad gateway</html>', {
            status: 502,
            statusText: 'Bad Gateway',
            headers: { 'Content-Type': 'text/html', 'X-Request-Id': '01JPROXY' },
          }),
      ),
    )
    const error = await caught(unwrap(client().GET('/ping')))
    expect(error).toMatchObject({
      status: 502,
      code: 'unexpected_response',
      title: 'Bad Gateway',
      requestId: '01JPROXY',
      fieldErrors: {},
    })
  })

  it('maps a network failure to code network', async () => {
    server.use(http.get(apiUrl('/ping'), () => HttpResponse.error()))
    const error = await caught(unwrap(client().GET('/ping')))
    expect(error).toMatchObject({ status: 0, code: 'network' })
    expect(error.isNetwork).toBe(true)
  })

  it('maps a thrown fetch to code network even without middleware', async () => {
    const error = await caught(unwrapBody(Promise.reject(new TypeError('Failed to fetch'))))
    expect(error.code).toBe('network')
  })

  it('returns the body as is for responses without an envelope', async () => {
    server.use(http.get(apiUrl('/ping'), () => new HttpResponse(null, { status: 204 })))
    await expect(unwrapBody(client().GET('/ping'))).resolves.toBeUndefined()
  })
})

describe('XSRF header', () => {
  const record = () => {
    const seen: Request[] = []
    server.use(
      http.all(`${TEST_API_ORIGIN}/v1/*`, ({ request }) => {
        seen.push(request)
        return HttpResponse.json({ data: { status: 'ok' } })
      }),
    )
    return seen
  }

  it('is sent on unsafe methods', async () => {
    const seen = record()
    // No POST endpoint exists yet; the untyped request method exercises the middleware.
    await client('other=1; XSRF-TOKEN=abc%3D%3D').request('post' as 'get', '/ping')
    expect(seen[0]?.method).toBe('POST')
    expect(seen[0]?.headers.get(XSRF_HEADER)).toBe('abc==')
  })

  it('is not sent on GET', async () => {
    const seen = record()
    await client('XSRF-TOKEN=abc').GET('/ping')
    expect(seen[0]?.headers.has(XSRF_HEADER)).toBe(false)
  })

  it('is omitted when the cookie is missing', async () => {
    const seen = record()
    await client('').request('post' as 'get', '/ping')
    expect(seen[0]?.headers.has(XSRF_HEADER)).toBe(false)
  })
})

describe('helpers', () => {
  it('reads a cookie by exact name', () => {
    expect(readCookie('XSRF-TOKEN-OLD=1; XSRF-TOKEN=a%20b', 'XSRF-TOKEN')).toBe('a b')
    expect(readCookie('', 'XSRF-TOKEN')).toBeUndefined()
  })

  it('appends the API version to the runtime base URL', () => {
    expect(apiBaseUrl('https://api.shp.localhost')).toBe('https://api.shp.localhost/v1')
    expect(apiBaseUrl('https://shp.localhost/api/')).toBe('https://shp.localhost/api/v1')
  })

  it('treats a problem body with a wrong shape as unexpected', () => {
    const error = toApiError(new Response(null, { status: 400 }), { message: 'Laravel default' })
    expect(error.code).toBe('unexpected_response')
    expect(error.title).toBe('HTTP 400')
  })
})

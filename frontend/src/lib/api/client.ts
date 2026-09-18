import createClient, { type Client, type Middleware } from 'openapi-fetch'
import type { RuntimeConfig } from '@/lib/config'
import { ApiError, toApiError, toNetworkError } from './errors'
import type { paths } from './schema'

/**
 * Typed client for the tenant API (docs/03-architecture/frontend.md, docs/07-api/documentation.md).
 * Paths come from `schema.d.ts`, generated from `backend/openapi.json` by `pnpm api:types`.
 */
export type ApiClient = Client<paths, 'application/json'>

export const XSRF_COOKIE = 'XSRF-TOKEN'
export const XSRF_HEADER = 'X-XSRF-TOKEN'

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS', 'TRACE'])

export type ApiClientOptions = {
  /** Runtime `apiBaseUrl` (for example `https://api.shp.localhost`); `/v1` is appended. */
  apiBaseUrl: string
  /** Defaults to the global `fetch`, resolved per request so test interceptors apply. */
  fetch?: typeof fetch
  /** Defaults to `document.cookie`; empty outside the browser. */
  readCookies?: () => string
}

export function readCookie(cookies: string, name: string): string | undefined {
  for (const part of cookies.split(';')) {
    const [key, ...value] = part.trim().split('=')
    if (key === name) {
      return decodeURIComponent(value.join('='))
    }
  }
  return undefined
}

function browserCookies(): string {
  return typeof document === 'undefined' ? '' : document.cookie
}

/** Sanctum CSRF: echo the `XSRF-TOKEN` cookie on unsafe methods; network failures become `ApiError('network')`. */
function sessionMiddleware(readCookies: () => string): Middleware {
  return {
    onRequest({ request }) {
      request.headers.set('Accept', 'application/json')
      if (!SAFE_METHODS.has(request.method.toUpperCase())) {
        const token = readCookie(readCookies(), XSRF_COOKIE)
        if (token) {
          request.headers.set(XSRF_HEADER, token)
        }
      }
      return request
    },
    onError({ error }) {
      return error instanceof ApiError ? error : toNetworkError(error)
    },
  }
}

export function apiBaseUrl(runtimeApiBaseUrl: string): string {
  return `${runtimeApiBaseUrl.replace(/\/+$/, '')}/v1`
}

export function createApiClient(options: ApiClientOptions): ApiClient {
  const fetcher =
    options.fetch ?? ((input: RequestInfo | URL, init?: RequestInit) => globalThis.fetch(input, init))
  const client = createClient<paths, 'application/json'>({
    baseUrl: apiBaseUrl(options.apiBaseUrl),
    credentials: 'include',
    fetch: fetcher,
  })
  client.use(sessionMiddleware(options.readCookies ?? browserCookies))
  return client
}

let sharedClient: ApiClient | undefined
let sharedOrigin: string | undefined

/** Called once at bootstrap with the runtime configuration. */
export function initApiClient(config: Pick<RuntimeConfig, 'apiBaseUrl'>): ApiClient {
  sharedClient = createApiClient({ apiBaseUrl: config.apiBaseUrl })
  sharedOrigin = config.apiBaseUrl.replace(/\/+$/, '')
  return sharedClient
}

export function api(): ApiClient {
  if (!sharedClient) {
    throw new Error('API client used before initApiClient() ran')
  }
  return sharedClient
}

/** Absolute URL of a route outside `/v1`, for example `/sanctum/csrf-cookie`. */
export function apiUrl(path: string): string {
  if (sharedOrigin === undefined) {
    throw new Error('API client used before initApiClient() ran')
  }
  return `${sharedOrigin}${path}`
}

export const CSRF_COOKIE_PATH = '/sanctum/csrf-cookie'

/**
 * Sanctum hands out the `XSRF-TOKEN` cookie here. The SPA must call it once before the first unsafe
 * request of a session (docs/07-api/authentication.md §1); afterwards the cookie is already present,
 * so the call is skipped.
 */
export async function ensureCsrfCookie(options: { force?: boolean } = {}): Promise<void> {
  if (!options.force && readCookie(browserCookies(), XSRF_COOKIE) !== undefined) {
    return
  }
  let response: Response
  try {
    response = await globalThis.fetch(apiUrl(CSRF_COOKIE_PATH), {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
  } catch (error) {
    throw toNetworkError(error)
  }
  if (!response.ok) {
    throw toApiError(response, undefined)
  }
}

type ClientResult<TData> = { data?: TData; error?: unknown; response: Response }

/** Resolves to the body, or throws `ApiError`. Use for responses without the `{data}` envelope (and 204). */
export async function unwrapBody<TData>(request: Promise<ClientResult<TData>>): Promise<TData> {
  let result: ClientResult<TData>
  try {
    result = await request
  } catch (error) {
    throw error instanceof ApiError ? error : toNetworkError(error)
  }
  if (!result.response.ok) {
    throw toApiError(result.response, result.error)
  }
  return result.data as TData
}

/** Resolves to `body.data` of a `JsonResource` response, or throws `ApiError`. */
export async function unwrap<TData>(request: Promise<ClientResult<{ data: TData }>>): Promise<TData> {
  const body = await unwrapBody(request)
  return body.data
}

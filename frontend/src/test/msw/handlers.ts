import { HttpResponse, http } from 'msw'
import type { ProblemDetails } from '@/lib/api/errors'
import type { paths } from '@/lib/api/schema'

/**
 * MSW handlers typed from the generated OpenAPI paths (docs/10-quality/testing.md).
 * A path that disappears from `backend/openapi.json` breaks `pnpm typecheck` here, so mocks cannot drift.
 */
export const TEST_API_ORIGIN = 'https://api.test'

type Method = 'get' | 'put' | 'post' | 'delete' | 'patch'

/** JSON body of the 200/201 response of an operation. */
export type ApiResponseBody<P extends keyof paths, M extends Method> = paths[P][M] extends {
  responses: infer R
}
  ? R extends { 200: { content: { 'application/json': infer B } } }
    ? B
    : R extends { 201: { content: { 'application/json': infer B } } }
      ? B
      : never
  : never

/** Absolute URL pattern for MSW: `/tickets/{ticket}` becomes `https://api.test/v1/tickets/:ticket`. */
export function apiUrl<P extends keyof paths>(path: P): string {
  return `${TEST_API_ORIGIN}/v1${String(path).replace(/\{([^}]+)\}/g, ':$1')}`
}

/** A problem-details response exactly as the backend renders it. */
export function problem(
  status: number,
  code: string,
  overrides: Partial<ProblemDetails> = {},
  requestId = '01J00000000000000000000000',
): Response {
  const body: ProblemDetails = {
    type: `https://docs.test/errors/${code}`,
    title: code.replace(/_/g, ' '),
    status,
    code,
    instance: '/v1/test',
    request_id: requestId,
    ...overrides,
  }
  return HttpResponse.json(body, {
    status,
    headers: { 'Content-Type': 'application/problem+json', 'X-Request-Id': requestId },
  })
}

/** A signed-in session as `GET /v1/me` renders it. `permissions` is empty until roadmap M1-09. */
export function sessionFixture(
  overrides: Partial<ApiResponseBody<'/me', 'get'>['data']> = {},
): ApiResponseBody<'/me', 'get'>['data'] {
  return {
    user: {
      id: '01a0b0a3-80b4-732e-9aaf-a1a943537bc9',
      name: 'Priya',
      email: 'priya@acme.test',
      is_active: true,
      preferences: {},
      last_login_at: '2026-09-17T18:35:00+00:00',
    },
    tenant: {
      id: '01a0b0a3-8006-73c3-a51f-08fb7be63ef4',
      slug: 'acme',
      name: 'Acme',
      status: 'active',
      timezone: 'Asia/Kathmandu',
      settings_version: 1,
      branding: { primary: null, logo_url: null, logo_dark_url: null },
      features: { realtime: false, exports: true },
    },
    permissions: [],
    agent_profile: null,
    unread_notifications: 0,
    ...overrides,
  }
}

export const handlers = [
  http.get(apiUrl('/ping'), () =>
    HttpResponse.json<ApiResponseBody<'/ping', 'get'>>({ data: { status: 'ok' } }),
  ),
  /** Sanctum hands out the XSRF cookie here; it lives outside `/v1`. */
  http.get(`${TEST_API_ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
  /** Nobody is signed in unless a test says otherwise. */
  http.get(apiUrl('/me'), () => problem(401, 'unauthenticated')),
]

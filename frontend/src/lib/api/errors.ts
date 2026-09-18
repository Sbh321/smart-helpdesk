import { z } from 'zod'

/**
 * RFC 9457 problem details as rendered by the API (docs/03-architecture/error-handling.md).
 * Unknown members are kept so new server fields do not break parsing.
 */
export const problemDetailsSchema = z.looseObject({
  type: z.string().optional(),
  title: z.string(),
  status: z.number().int(),
  detail: z.string().optional(),
  code: z.string().min(1),
  instance: z.string().optional(),
  request_id: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
  meta: z.record(z.string(), z.unknown()).optional(),
})

export type ProblemDetails = z.infer<typeof problemDetailsSchema>

/** Client-side codes for failures that never produced problem details. */
export const clientErrorCodes = {
  network: 'network',
  unexpectedResponse: 'unexpected_response',
} as const

export type FieldErrors = Record<string, string[]>

type ApiErrorInit = {
  status: number
  code: string
  title: string
  detail?: string | undefined
  requestId?: string | undefined
  fieldErrors?: FieldErrors | undefined
  meta?: Record<string, unknown> | undefined
  cause?: unknown
}

/** The only error type API callers see. `status` is 0 when no response arrived. */
export class ApiError extends Error {
  override readonly name = 'ApiError'
  readonly status: number
  readonly code: string
  readonly title: string
  readonly detail: string | undefined
  readonly requestId: string | undefined
  readonly fieldErrors: FieldErrors
  readonly meta: Record<string, unknown> | undefined

  constructor(init: ApiErrorInit) {
    super(init.detail ?? init.title, { cause: init.cause })
    this.status = init.status
    this.code = init.code
    this.title = init.title
    this.detail = init.detail
    this.requestId = init.requestId
    this.fieldErrors = init.fieldErrors ?? {}
    this.meta = init.meta
  }

  get isNetwork(): boolean {
    return this.code === clientErrorCodes.network
  }

  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.fieldErrors).length > 0
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

/**
 * Converts an error response into an `ApiError`. `body` is what openapi-fetch returned as `error`:
 * parsed JSON, or the raw text when the body was not JSON (for example an HTML page from a proxy).
 */
export function toApiError(response: Response, body: unknown): ApiError {
  const headerRequestId = response.headers.get('X-Request-Id') ?? undefined
  const parsed = problemDetailsSchema.safeParse(body)

  if (parsed.success) {
    const problem = parsed.data
    return new ApiError({
      status: response.status,
      code: problem.code,
      title: problem.title,
      detail: problem.detail,
      requestId: problem.request_id ?? headerRequestId,
      fieldErrors: problem.errors,
      meta: problem.meta,
    })
  }

  return new ApiError({
    status: response.status,
    code: clientErrorCodes.unexpectedResponse,
    title: response.statusText || `HTTP ${response.status}`,
    requestId: headerRequestId,
    cause: body,
  })
}

/** A request that never got a response: offline, DNS, CORS or TLS failure. */
export function toNetworkError(cause: unknown): ApiError {
  return new ApiError({ status: 0, code: clientErrorCodes.network, title: 'Network error', cause })
}

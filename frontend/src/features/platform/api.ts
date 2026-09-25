import { keepPreviousData, type QueryClient, queryOptions } from '@tanstack/react-query'
import { readCookie } from '@/lib/api/client'
import { isApiError, toApiError, toNetworkError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'

/**
 * Platform API on the admin host (docs/07-api/authentication.md §6): same-origin `/platform-api/*`,
 * its own session cookie and its own CSRF pair (`XSRF-TOKEN-PLATFORM` echoed as `X-XSRF-TOKEN-PLATFORM`).
 * It is not in the tenant OpenAPI document, so these calls use `fetch` with hand-written types.
 *
 * The console (ADR-0025, M6): workspaces, subscriptions, plans, payments, admins, settings, dashboard.
 */

export const PLATFORM_XSRF_COOKIE = 'XSRF-TOKEN-PLATFORM'
export const PLATFORM_XSRF_HEADER = 'X-XSRF-TOKEN-PLATFORM'

export type PlatformUser = {
  id: string
  name: string
  email: string
  last_login_at: string | null
}

export type PlanKind = 'trial' | 'paid'
export type SubscriptionState = 'trialing' | 'active' | 'grace' | 'expired' | 'none'
export type PaymentStatus = 'pending' | 'approved' | 'rejected'
export type PaymentMethod = 'bank_transfer' | 'wallet' | 'cash' | 'other'

/** A plan (ADR-0025 §1). Amounts are integers in minor units. */
export type Plan = {
  id: string
  code: string
  name: string
  description: string | null
  kind: PlanKind
  price_minor: number
  currency: string
  period_months: number | null
  trial_days: number | null
  is_active: boolean
  sort_order: number
  subscriptions_count?: number
}

/** A workspace's subscription as it reads now (ADR-0025 §2). */
export type Subscription = {
  state: SubscriptionState
  plan: Plan | null
  ends_at: string | null
  grace_ends_at: string | null
  days_left: number | null
  read_only: boolean
}

export type PlatformTenant = {
  id: string
  slug: string
  name: string
  status: string
  placement: string
  owner_email: string | null
  timezone: string
  suspended_at: string | null
  archived_at: string | null
  created_at: string | null
  subscription: Subscription
}

export type Receipt = {
  id: string
  name: string
  mime_type: string
  size_bytes: number
  width: number | null
  height: number | null
  has_thumb: boolean
  has_preview: boolean
}

export type Payment = {
  id: string
  plan: Plan
  periods: number
  amount_minor: number
  currency: string
  expected_minor: number
  paid_on: string
  method: PaymentMethod
  reference: string | null
  note: string | null
  status: PaymentStatus
  rejection_reason: string | null
  submitted_by: { name: string; email: string } | null
  recorded_by_platform: boolean
  reviewed_at: string | null
  period_starts_at: string | null
  period_ends_at: string | null
  created_at: string | null
  receipt: Receipt | null
  workspace?: { id: string; slug: string; name: string }
}

export type PlatformAdmin = {
  id: string
  name: string
  email: string
  status: 'active' | 'invited' | 'deactivated'
  is_you: boolean
  last_login_at: string | null
  deactivated_at: string | null
  invitation_expires_at: string | null
  created_at: string | null
}

export type PlatformSettings = { signup_enabled: boolean; grace_days: number; payment_instructions: string }

export type PlatformDashboard = {
  workspaces: {
    total: number
    active: number
    suspended: number
    archived: number
    by_subscription: Record<SubscriptionState, number>
  }
  new_workspaces: { week: string; console: number; signup: number }[]
  inside: { active_users: number; tickets_30d: number; measured_at: string }
  revenue: {
    currency: string
    this_month_minor: number
    last_month_minor: number
    months: { month: string; amount_minor: number }[]
  }[]
  payments: { pending: number }
  ending_soon: {
    workspace: { id: string; slug: string; name: string }
    plan: string
    state: SubscriptionState
    ends_at: string
    days_left: number | null
  }[]
  recent_workspaces: {
    id: string
    slug: string
    name: string
    created_at: string | null
    source: 'signup' | 'console'
  }[]
}

/** Laravel's paginator as the platform API sends it. */
export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

async function platformRequest<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    const token = readCookie(document.cookie, PLATFORM_XSRF_COOKIE)
    if (token) {
      headers[PLATFORM_XSRF_HEADER] = token
    }
  }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  let response: Response
  try {
    response = await globalThis.fetch(`/platform-api${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch (error) {
    throw toNetworkError(error)
  }
  const data: unknown = response.status === 204 ? undefined : await response.json().catch(() => undefined)
  if (!response.ok) {
    throw toApiError(response, data)
  }
  return data as T
}

/** Starts the platform session and sets the platform CSRF cookie; needed before the first unsafe call. */
async function ensurePlatformCsrfCookie(): Promise<void> {
  if (readCookie(document.cookie, PLATFORM_XSRF_COOKIE) === undefined) {
    await platformRequest<void>('GET', '/csrf-cookie')
  }
}

export async function platformLogin(input: { email: string; password: string }): Promise<void> {
  await ensurePlatformCsrfCookie()
  await platformRequest('POST', '/auth/login', input)
}

/** The platform-only hosts the console hands over to (ADR-0024). */
export type PlatformTarget = 'docs' | 'monitor'

/** A one-time link that signs the admin in to the platform documentation or monitoring host (ADR-0024, M5-06). */
export async function platformHandoff(target: PlatformTarget, next: string): Promise<string> {
  await ensurePlatformCsrfCookie()
  const response = await platformRequest<{ data: { url: string } }>('POST', '/handoff', { target, next })
  return response.data.url
}

export async function platformLogout(): Promise<void> {
  await ensurePlatformCsrfCookie()
  await platformRequest('POST', '/auth/logout')
}

/** `GET /platform-api/me`; a 401 or 419 means "nobody is signed in" and resolves to `null`. */
export const platformSessionQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.me(),
    queryFn: async (): Promise<PlatformUser | null> => {
      try {
        return (await platformRequest<{ data: PlatformUser }>('GET', '/me')).data
      } catch (error) {
        if (isApiError(error) && (error.status === 401 || error.status === 419)) {
          return null
        }
        throw error
      }
    },
    retry: false,
    staleTime: 30_000,
  })

export function ensurePlatformSession(queryClient: QueryClient): Promise<PlatformUser | null> {
  return queryClient.ensureQueryData(platformSessionQuery())
}

export function reloadPlatformSession(queryClient: QueryClient): Promise<PlatformUser | null> {
  return queryClient.fetchQuery({ ...platformSessionQuery(), staleTime: 0 })
}

function toQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== '') search.set(key, String(value))
  }
  const text = search.toString()
  return text ? `?${text}` : ''
}

/** An unsafe call: the CSRF cookie first, then the request. */
async function send<T>(method: string, path: string, body?: unknown): Promise<T> {
  await ensurePlatformCsrfCookie()
  return platformRequest<T>(method, path, body)
}

export type TenantListParams = { page?: number; search?: string; status?: string; subscription?: string }

export const platformTenantsQuery = (params: TenantListParams = {}) =>
  queryOptions({
    queryKey: queryKeys.platform.tenants(params),
    queryFn: () => platformRequest<Paginated<PlatformTenant>>('GET', `/tenants${toQuery(params)}`),
    placeholderData: keepPreviousData,
  })

export const platformTenantQuery = (id: string) =>
  queryOptions({
    queryKey: queryKeys.platform.tenant(id),
    queryFn: async () => (await platformRequest<{ data: PlatformTenant }>('GET', `/tenants/${id}`)).data,
  })

export const platformPlansQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.plans(),
    queryFn: async () => (await platformRequest<{ data: Plan[] }>('GET', '/plans')).data,
  })

export type PaymentListParams = { page?: number; status?: PaymentStatus; tenant_id?: string }

export const platformPaymentsQuery = (params: PaymentListParams = {}) =>
  queryOptions({
    queryKey: queryKeys.platform.payments(params),
    queryFn: () => platformRequest<Paginated<Payment>>('GET', `/payments${toQuery(params)}`),
    placeholderData: keepPreviousData,
  })

export const platformPaymentQuery = (id: string) =>
  queryOptions({
    queryKey: [...queryKeys.platform.payments(), 'detail', id] as const,
    queryFn: async () => (await platformRequest<{ data: Payment }>('GET', `/payments/${id}`)).data,
  })

export const platformAdminsQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.admins(),
    queryFn: async () => (await platformRequest<{ data: PlatformAdmin[] }>('GET', '/admins')).data,
  })

export const platformSettingsQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.settings(),
    queryFn: async () => (await platformRequest<{ data: PlatformSettings }>('GET', '/settings')).data,
  })

export const platformDashboardQuery = () =>
  queryOptions({
    queryKey: queryKeys.platform.dashboard(),
    queryFn: async () => (await platformRequest<{ data: PlatformDashboard }>('GET', '/dashboard')).data,
  })

/** Where the console opens a payment's receipt: the API redirects to a short-lived storage URL. */
export const receiptUrl = (paymentId: string) => `/platform-api/payments/${paymentId}/receipt`

export type NewTenantInput = {
  name: string
  slug: string
  owner_name?: string
  owner_email: string
  timezone?: string
  plan_id?: string | null
  periods?: number
}

export const platform = {
  createTenant: async (input: NewTenantInput) =>
    (await send<{ data: PlatformTenant }>('POST', '/tenants', input)).data,
  updateTenant: async (id: string, input: { name?: string; owner_email?: string; timezone?: string }) =>
    (await send<{ data: PlatformTenant }>('PATCH', `/tenants/${id}`, input)).data,
  suspendTenant: async (id: string, reason?: string) =>
    (await send<{ data: PlatformTenant }>('POST', `/tenants/${id}/suspend`, reason ? { reason } : {})).data,
  reactivateTenant: async (id: string) =>
    (await send<{ data: PlatformTenant }>('POST', `/tenants/${id}/reactivate`)).data,
  changeSubscription: async (tenantId: string, input: { plan_id: string; ends_at: string }) =>
    (await send<{ data: Subscription }>('PUT', `/tenants/${tenantId}/subscription`, input)).data,
  recordPayment: async (
    tenantId: string,
    input: {
      plan_id: string
      periods: number
      amount_minor: number
      paid_on: string
      method: PaymentMethod
      reference?: string
      note?: string
    },
  ) => (await send<{ data: Payment }>('POST', `/tenants/${tenantId}/payments`, input)).data,
  createPlan: async (input: Partial<Plan> & { code: string; kind: PlanKind; name: string }) =>
    (await send<{ data: Plan }>('POST', '/plans', input)).data,
  updatePlan: async (id: string, input: Partial<Plan>) =>
    (await send<{ data: Plan }>('PATCH', `/plans/${id}`, input)).data,
  approvePayment: async (id: string) =>
    (await send<{ data: Payment }>('POST', `/payments/${id}/approve`)).data,
  rejectPayment: async (id: string, reason: string) =>
    (await send<{ data: Payment }>('POST', `/payments/${id}/reject`, { reason })).data,
  inviteAdmin: async (input: { name: string; email: string }) =>
    (await send<{ data: PlatformAdmin }>('POST', '/admins', input)).data,
  resendInvitation: async (id: string) =>
    (await send<{ data: PlatformAdmin }>('POST', `/admins/${id}/resend-invitation`)).data,
  revokeInvitation: (id: string) => send<void>('DELETE', `/admins/${id}/invitation`),
  deactivateAdmin: async (id: string) =>
    (await send<{ data: PlatformAdmin }>('POST', `/admins/${id}/deactivate`)).data,
  reactivateAdmin: async (id: string) =>
    (await send<{ data: PlatformAdmin }>('POST', `/admins/${id}/reactivate`)).data,
  updateSettings: async (input: Partial<PlatformSettings>) =>
    (await send<{ data: PlatformSettings }>('PATCH', '/settings', input)).data,
  updateProfile: async (input: { name: string }) =>
    (await send<{ data: PlatformUser }>('PATCH', '/me', input)).data,
  changePassword: (input: { current_password: string; password: string; password_confirmation: string }) =>
    send<void>('PUT', '/me/password', input),
  forgotPassword: (email: string) => send<void>('POST', '/auth/forgot-password', { email }),
  resetPassword: (input: { token: string; email: string; password: string; password_confirmation: string }) =>
    send<void>('POST', '/auth/reset-password', input),
  invitation: async (token: string) =>
    (
      await platformRequest<{ data: { email: string; name: string; expires_at: string } }>(
        'GET',
        `/auth/invitations/${token}`,
      )
    ).data,
  acceptInvitation: async (
    token: string,
    input: { name: string; password: string; password_confirmation: string },
  ) => (await send<{ data: PlatformUser }>('POST', `/auth/invitations/${token}/accept`, input)).data,
}

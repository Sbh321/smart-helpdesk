import { HttpResponse, http } from 'msw'
import type { SetupWorker } from 'msw/browser'
import type {
  Payment,
  Plan,
  PlatformAdmin,
  PlatformDashboard,
  PlatformSettings,
  PlatformTenant,
  Subscription,
} from '@/features/platform'
import { problem } from './handlers'
import { validationFailed } from './list'

/**
 * The platform API on the admin host (ADR-0025) as an in-memory service for console tests: plans,
 * workspaces with subscriptions, payments, admins, settings and the dashboard. `/platform-api/*` is
 * same-origin, so the handlers match any origin.
 */
export const PLATFORM_ADMIN = {
  id: 'admin-1',
  name: 'Platform Admin',
  email: 'admin@platform.test',
  last_login_at: null,
}

const TRIAL: Plan = {
  id: 'plan-trial',
  code: 'trial',
  name: 'Free trial',
  description: null,
  kind: 'trial',
  price_minor: 0,
  currency: 'NPR',
  period_months: null,
  trial_days: 14,
  is_active: true,
  sort_order: 0,
  subscriptions_count: 1,
}
const STANDARD: Plan = {
  id: 'plan-standard',
  code: 'standard',
  name: 'Standard',
  description: null,
  kind: 'paid',
  price_minor: 250000,
  currency: 'NPR',
  period_months: 1,
  trial_days: null,
  is_active: true,
  sort_order: 1,
  subscriptions_count: 1,
}

function subscription(
  plan: Plan | null,
  state: Subscription['state'],
  endsAt: string | null,
  daysLeft: number | null,
): Subscription {
  return {
    state,
    plan,
    ends_at: endsAt,
    grace_ends_at: endsAt ? new Date(Date.parse(endsAt) + 7 * 86_400_000).toISOString() : null,
    days_left: daysLeft,
    read_only: state === 'expired',
  }
}

function tenant(overrides: Partial<PlatformTenant>): PlatformTenant {
  return {
    id: 't1',
    slug: 'acme',
    name: 'Acme Support',
    status: 'active',
    placement: 'shared',
    owner_email: 'meera@acme.test',
    timezone: 'Asia/Kathmandu',
    suspended_at: null,
    archived_at: null,
    created_at: '2026-09-01T04:15:00Z',
    subscription: subscription(STANDARD, 'active', '2026-12-01T00:00:00Z', 60),
    ...overrides,
  }
}

function payment(overrides: Partial<Payment>): Payment {
  return {
    id: 'pay-1',
    plan: STANDARD,
    periods: 3,
    amount_minor: 700000,
    currency: 'NPR',
    expected_minor: 750000,
    paid_on: '2026-09-20',
    method: 'bank_transfer',
    reference: 'TRX-1001',
    note: 'Paid from the office account.',
    status: 'pending',
    rejection_reason: null,
    submitted_by: { name: 'Meera', email: 'meera@acme.test' },
    recorded_by_platform: false,
    reviewed_at: null,
    period_starts_at: null,
    period_ends_at: null,
    created_at: '2026-09-21T08:00:00Z',
    receipt: {
      id: 'media-1',
      name: 'receipt.png',
      mime_type: 'image/png',
      size_bytes: 2048,
      width: 1,
      height: 1,
      has_thumb: false,
      has_preview: false,
    },
    workspace: { id: 't1', slug: 'acme', name: 'Acme Support' },
    ...overrides,
  }
}

export type PlatformState = {
  signedIn: boolean
  plans: Plan[]
  tenants: PlatformTenant[]
  payments: Payment[]
  admins: PlatformAdmin[]
  settings: PlatformSettings
  requests: { method: string; path: string; body: unknown }[]
}

export function platformState(overrides: Partial<PlatformState> = {}): PlatformState {
  return {
    signedIn: true,
    plans: [TRIAL, STANDARD],
    tenants: [
      tenant({}),
      tenant({
        id: 't2',
        slug: 'globex',
        name: 'Globex',
        status: 'suspended',
        owner_email: null,
        timezone: 'UTC',
        suspended_at: '2026-09-20T00:00:00Z',
        created_at: null,
        subscription: subscription(TRIAL, 'trialing', '2026-09-30T00:00:00Z', 4),
      }),
    ],
    payments: [
      payment({}),
      payment({
        id: 'pay-2',
        status: 'approved',
        amount_minor: 250000,
        expected_minor: 250000,
        periods: 1,
        reviewed_at: '2026-09-02T00:00:00Z',
        period_starts_at: '2026-09-02T00:00:00Z',
        period_ends_at: '2026-10-02T00:00:00Z',
        created_at: '2026-09-01T08:00:00Z',
      }),
    ],
    admins: [
      {
        id: 'admin-1',
        name: 'Platform Admin',
        email: 'admin@platform.test',
        status: 'active',
        is_you: true,
        last_login_at: '2026-09-25T08:00:00Z',
        deactivated_at: null,
        invitation_expires_at: null,
        created_at: '2026-09-01T00:00:00Z',
      },
      {
        id: 'admin-2',
        name: 'Sam Rai',
        email: 'sam@platform.test',
        status: 'active',
        is_you: false,
        last_login_at: null,
        deactivated_at: null,
        invitation_expires_at: null,
        created_at: '2026-09-02T00:00:00Z',
      },
    ],
    settings: { signup_enabled: true, grace_days: 7, payment_instructions: 'Nabil Bank, account 0123456789' },
    requests: [],
    ...overrides,
  }
}

const DASHBOARD: PlatformDashboard = {
  workspaces: {
    total: 2,
    active: 1,
    suspended: 1,
    archived: 0,
    by_subscription: { trialing: 1, active: 1, grace: 0, expired: 0, none: 0 },
  },
  new_workspaces: Array.from({ length: 12 }, (_, week) => ({
    week: new Date(Date.UTC(2026, 6, 6 + week * 7)).toISOString().slice(0, 10),
    console: week % 3 === 0 ? 1 : 0,
    signup: week % 4 === 0 ? 2 : 0,
  })),
  inside: { active_users: 14, tickets_30d: 132, measured_at: '2026-09-25T08:00:00Z' },
  revenue: [
    {
      currency: 'NPR',
      this_month_minor: 250000,
      last_month_minor: 500000,
      months: Array.from({ length: 12 }, (_, index) => ({
        month: `${index < 3 ? 2025 : 2026}-${String(((index + 9) % 12) + 1).padStart(2, '0')}`,
        amount_minor: index * 50000,
      })),
    },
  ],
  payments: { pending: 1 },
  ending_soon: [
    {
      workspace: { id: 't2', slug: 'globex', name: 'Globex' },
      plan: 'Free trial',
      state: 'trialing',
      ends_at: '2026-09-30T00:00:00Z',
      days_left: 4,
    },
  ],
  recent_workspaces: [
    { id: 't2', slug: 'globex', name: 'Globex', created_at: '2026-09-20T00:00:00Z', source: 'signup' },
    { id: 't1', slug: 'acme', name: 'Acme Support', created_at: '2026-09-01T04:15:00Z', source: 'console' },
  ],
}

const PIXEL = Uint8Array.from(
  atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
  (char) => char.charCodeAt(0),
)

function page<T>(rows: T[]) {
  return { data: rows, meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length } }
}

/** Installs the platform API on `worker` for one test; the returned state can be read and changed. */
export function usePlatformApi(worker: SetupWorker, state: PlatformState = platformState()): PlatformState {
  const guard = () => (state.signedIn ? null : problem(401, 'unauthenticated'))
  const record = async (request: Request) => {
    const body =
      request.method === 'GET'
        ? undefined
        : await request
            .clone()
            .json()
            .catch(() => undefined)
    state.requests.push({
      method: request.method,
      path: new URL(request.url).pathname.replace('/platform-api', ''),
      body,
    })
    return body as Record<string, unknown> | undefined
  }

  worker.use(
    http.get('*/platform-api/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
    http.get('*/platform-api/me', () => guard() ?? HttpResponse.json({ data: PLATFORM_ADMIN })),
    http.post('*/platform-api/auth/login', async ({ request }) => {
      const body = (await request.json()) as { email: string; password: string }
      if (body.password !== 'password') return problem(401, 'invalid_credentials')
      state.signedIn = true
      return HttpResponse.json({ data: PLATFORM_ADMIN })
    }),
    http.post('*/platform-api/auth/logout', () => {
      state.signedIn = false
      return new HttpResponse(null, { status: 204 })
    }),
    http.post('*/platform-api/auth/forgot-password', async ({ request }) => {
      await record(request)
      return HttpResponse.json({ data: { status: 'sent' } }, { status: 202 })
    }),
    http.post('*/platform-api/auth/reset-password', async ({ request }) => {
      const body = await record(request)
      if (body?.token !== 'good-token')
        return problem(422, 'link_expired', {
          detail: 'This reset link has expired or was already used. Ask for a new one.',
        })
      return new HttpResponse(null, { status: 204 })
    }),
    http.get('*/platform-api/auth/invitations/:token', ({ params }) =>
      params.token === 'invite-token'
        ? HttpResponse.json({
            data: { email: 'rita@platform.test', name: 'Rita', expires_at: '2026-09-27T00:00:00Z' },
          })
        : problem(422, 'link_expired', {
            detail: 'This invitation has expired or was already used. Ask an admin to send a new one.',
          }),
    ),
    http.post('*/platform-api/auth/invitations/:token/accept', async ({ request }) => {
      await record(request)
      state.signedIn = true
      return HttpResponse.json({
        data: { ...PLATFORM_ADMIN, name: 'Rita Shah', email: 'rita@platform.test' },
      })
    }),
    http.get('*/platform-api/dashboard', () => guard() ?? HttpResponse.json({ data: DASHBOARD })),
    http.get('*/platform-api/tenants', ({ request }) => {
      const blocked = guard()
      if (blocked) return blocked
      const url = new URL(request.url)
      const states = url.searchParams.get('subscription')?.split(',')
      const search = url.searchParams.get('search')?.toLowerCase()
      return HttpResponse.json(
        page(
          state.tenants.filter(
            (row) =>
              (!states || states.includes(row.subscription.state)) &&
              (!search || row.name.toLowerCase().includes(search) || row.slug.includes(search)),
          ),
        ),
      )
    }),
    http.post('*/platform-api/tenants', async ({ request }) => {
      const body = (await record(request)) as {
        slug: string
        name: string
        owner_email: string
        plan_id?: string
      }
      if (state.tenants.some((row) => row.slug === body.slug))
        return validationFailed({ slug: ['The slug has already been taken.'] })
      const plan = state.plans.find((candidate) => candidate.id === body.plan_id) ?? TRIAL
      const created = tenant({
        id: `t${state.tenants.length + 1}`,
        slug: body.slug,
        name: body.name,
        owner_email: body.owner_email,
        subscription: subscription(
          plan,
          plan.kind === 'trial' ? 'trialing' : 'active',
          '2026-10-09T00:00:00Z',
          14,
        ),
      })
      state.tenants.push(created)
      return HttpResponse.json({ data: created }, { status: 201 })
    }),
    http.get('*/platform-api/tenants/:id', ({ params }) => {
      const found = state.tenants.find((row) => row.id === params.id)
      return found ? HttpResponse.json({ data: found }) : problem(404, 'not_found')
    }),
    http.patch('*/platform-api/tenants/:id', async ({ params, request }) => {
      const body = (await record(request)) as Partial<PlatformTenant>
      const found = state.tenants.find((row) => row.id === params.id)
      if (!found) return problem(404, 'not_found')
      Object.assign(found, body)
      return HttpResponse.json({ data: found })
    }),
    http.post('*/platform-api/tenants/:id/suspend', async ({ params, request }) => {
      await record(request)
      const found = state.tenants.find((row) => row.id === params.id)
      if (!found) return problem(404, 'not_found')
      found.status = 'suspended'
      return HttpResponse.json({ data: found })
    }),
    http.post('*/platform-api/tenants/:id/reactivate', ({ params }) => {
      const found = state.tenants.find((row) => row.id === params.id)
      if (!found) return problem(404, 'not_found')
      found.status = 'active'
      return HttpResponse.json({ data: found })
    }),
    http.put('*/platform-api/tenants/:id/subscription', async ({ params, request }) => {
      const body = (await record(request)) as { plan_id: string; ends_at: string }
      const found = state.tenants.find((row) => row.id === params.id)
      const plan = state.plans.find((candidate) => candidate.id === body.plan_id)
      if (!found || !plan) return problem(404, 'not_found')
      found.subscription = subscription(plan, plan.kind === 'trial' ? 'trialing' : 'active', body.ends_at, 30)
      return HttpResponse.json({ data: found.subscription })
    }),
    http.post('*/platform-api/tenants/:id/payments', async ({ params, request }) => {
      const body = (await record(request)) as { amount_minor: number; periods: number }
      const created = payment({
        id: `pay-${state.payments.length + 1}`,
        status: 'approved',
        recorded_by_platform: true,
        receipt: null,
        submitted_by: null,
        amount_minor: body.amount_minor,
        periods: body.periods,
        period_ends_at: '2027-01-01T00:00:00Z',
        workspace: { id: String(params.id), slug: 'acme', name: 'Acme Support' },
      })
      state.payments.push(created)
      return HttpResponse.json({ data: created }, { status: 201 })
    }),
    http.get('*/platform-api/plans', () => guard() ?? HttpResponse.json({ data: state.plans })),
    http.post('*/platform-api/plans', async ({ request }) => {
      const body = (await record(request)) as Plan
      if (body.kind === 'trial' && body.is_active) {
        return validationFailed({
          is_active: [
            'Only one trial plan can be active: new workspaces start on it. Archive the other trial plan first.',
          ],
        })
      }
      const created = { ...STANDARD, ...body, id: `plan-${state.plans.length + 1}`, subscriptions_count: 0 }
      state.plans.push(created)
      return HttpResponse.json({ data: created }, { status: 201 })
    }),
    http.patch('*/platform-api/plans/:id', async ({ params, request }) => {
      const body = (await record(request)) as Partial<Plan>
      const found = state.plans.find((candidate) => candidate.id === params.id)
      if (!found) return problem(404, 'not_found')
      Object.assign(found, body)
      return HttpResponse.json({ data: found })
    }),
    http.get('*/platform-api/payments', ({ request }) => {
      const blocked = guard()
      if (blocked) return blocked
      const url = new URL(request.url)
      const status = url.searchParams.get('status')
      const tenantId = url.searchParams.get('tenant_id')
      return HttpResponse.json(
        page(
          state.payments.filter(
            (row) => (!status || row.status === status) && (!tenantId || row.workspace?.id === tenantId),
          ),
        ),
      )
    }),
    http.get('*/platform-api/payments/:id', ({ params }) => {
      const found = state.payments.find((row) => row.id === params.id)
      return found ? HttpResponse.json({ data: found }) : problem(404, 'not_found')
    }),
    http.get(
      '*/platform-api/payments/:id/receipt',
      () => new HttpResponse(PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    ),
    http.post('*/platform-api/payments/:id/approve', async ({ params, request }) => {
      await record(request)
      const found = state.payments.find((row) => row.id === params.id)
      if (!found) return problem(404, 'not_found')
      Object.assign(found, {
        status: 'approved',
        period_starts_at: '2026-12-01T00:00:00Z',
        period_ends_at: '2027-03-01T00:00:00Z',
      })
      return HttpResponse.json({ data: found })
    }),
    http.post('*/platform-api/payments/:id/reject', async ({ params, request }) => {
      const body = (await record(request)) as { reason?: string }
      if (!body.reason || body.reason.length < 5)
        return validationFailed({ reason: ['The reason field must be at least 5 characters.'] })
      const found = state.payments.find((row) => row.id === params.id)
      if (!found) return problem(404, 'not_found')
      Object.assign(found, { status: 'rejected', rejection_reason: body.reason })
      return HttpResponse.json({ data: found })
    }),
    http.get('*/platform-api/admins', () => guard() ?? HttpResponse.json({ data: state.admins })),
    http.post('*/platform-api/admins', async ({ request }) => {
      const body = (await record(request)) as { name: string; email: string }
      if (state.admins.some((admin) => admin.email === body.email)) {
        return validationFailed({ email: ['This person is already a platform admin, or was invited.'] })
      }
      const created: PlatformAdmin = {
        id: `admin-${state.admins.length + 1}`,
        name: body.name,
        email: body.email,
        status: 'invited',
        is_you: false,
        last_login_at: null,
        deactivated_at: null,
        invitation_expires_at: '2026-09-27T08:00:00Z',
        created_at: '2026-09-25T08:00:00Z',
      }
      state.admins.push(created)
      return HttpResponse.json({ data: created }, { status: 201 })
    }),
    http.post('*/platform-api/admins/:id/deactivate', ({ params }) => {
      const found = state.admins.find((admin) => admin.id === params.id)
      if (!found) return problem(404, 'not_found')
      found.status = 'deactivated'
      return HttpResponse.json({ data: found })
    }),
    http.post('*/platform-api/admins/:id/reactivate', ({ params }) => {
      const found = state.admins.find((admin) => admin.id === params.id)
      if (!found) return problem(404, 'not_found')
      found.status = 'active'
      return HttpResponse.json({ data: found })
    }),
    http.get('*/platform-api/settings', () => guard() ?? HttpResponse.json({ data: state.settings })),
    http.patch('*/platform-api/settings', async ({ request }) => {
      const body = (await record(request)) as Partial<PlatformSettings>
      if (body.grace_days !== undefined && (body.grace_days < 0 || body.grace_days > 60)) {
        return validationFailed({ grace_days: ['The grace days field must be between 0 and 60.'] })
      }
      Object.assign(state.settings, body)
      return HttpResponse.json({ data: state.settings })
    }),
    http.patch('*/platform-api/me', async ({ request }) => {
      const body = (await record(request)) as { name: string }
      return HttpResponse.json({ data: { ...PLATFORM_ADMIN, name: body.name } })
    }),
    http.put('*/platform-api/me/password', async ({ request }) => {
      const body = (await record(request)) as { current_password: string }
      if (body.current_password !== 'password')
        return validationFailed({ current_password: ['This is not your current password.'] })
      return new HttpResponse(null, { status: 204 })
    }),
  )

  return state
}

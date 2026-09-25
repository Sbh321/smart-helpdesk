import { z } from 'zod'
import { choiceFilter, defineListSchema, multiFilter } from '@/lib/list-params'
import type { PaymentStatus, SubscriptionState, TenantListParams } from './api'

export const SUBSCRIPTION_STATES = [
  'trialing',
  'active',
  'grace',
  'expired',
  'none',
] as const satisfies readonly SubscriptionState[]

/** The workspaces list in the address bar: search, status, subscription states, page. */
export const tenantListSchema = defineListSchema({
  sortFields: ['created_at'] as const,
  defaultSort: '-created_at',
  filters: {
    status: choiceFilter(['active', 'suspended', 'archived']),
    subscription: multiFilter(z.enum(SUBSCRIPTION_STATES)),
  },
})

/** The platform API takes plain parameters, not `filter[…]`. */
export function tenantApiParams(
  params: ReturnType<typeof tenantListSchema.parse>,
): TenantListParams & { per_page: number } {
  return {
    page: params.page,
    per_page: params.per_page,
    search: params.search,
    status: params.filters.status,
    subscription: params.filters.subscription?.join(','),
  }
}

export const PAYMENT_TABS = ['pending', 'approved', 'rejected', 'all'] as const
export type PaymentTab = (typeof PAYMENT_TABS)[number]

/** The payments queue in the address bar: a tab, a page, and the payment under review. */
export const paymentsSearchSchema = z.object({
  tab: z.enum(PAYMENT_TABS).catch('pending').default('pending'),
  page: z.coerce.number().int().min(1).catch(1).default(1),
  // Any short id: a wrong one opens nothing rather than breaking the page.
  payment: z.string().min(1).max(64).optional().catch(undefined),
})

export const statusForTab = (tab: PaymentTab): PaymentStatus | undefined => (tab === 'all' ? undefined : tab)

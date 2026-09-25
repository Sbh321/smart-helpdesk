import { queryOptions } from '@tanstack/react-query'
import { api, unwrap } from '@/lib/api/client'
import type { components } from '@/lib/api/schema'

export type BillingPlan = components['schemas']['PlanResource']
export type BillingPayment = components['schemas']['PaymentResource']
export type WorkspaceSubscription = components['schemas']['SubscriptionResource']

export type PaymentInput = {
  plan_id: string
  periods: number
  amount_minor: number
  paid_on: string
  method: 'bank_transfer' | 'wallet' | 'cash' | 'other'
  reference?: string
  note?: string
  receipt_media_id: string
}

/** The workspace's billing (ADR-0025 §3): its subscription, the paid plans, its payments, where to pay. */
export const billingQuery = (tenantId: string) =>
  queryOptions({
    queryKey: [tenantId, 'billing'] as const,
    queryFn: () => unwrap(api().GET('/billing')),
  })

export function submitPayment(input: PaymentInput) {
  return unwrap(api().POST('/billing/payments', { body: input }))
}

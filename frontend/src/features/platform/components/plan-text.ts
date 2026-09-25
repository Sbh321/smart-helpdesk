import { copy, fill } from '@/copy/en'
import { formatMoney } from '@/lib/format/money'
import type { Plan } from '../api'

const text = copy.platform.plans

/** "NPR 2,500.00 per month", "NPR 25,000.00 per 12 months", "Free". */
export function planPriceText(
  plan: Pick<Plan, 'kind' | 'price_minor' | 'currency' | 'period_months'>,
): string {
  if (plan.kind === 'trial') return text.free
  const price = formatMoney(plan.price_minor, plan.currency)
  return plan.period_months === 1
    ? fill(text.perMonth, { price })
    : fill(text.perMonths, { price, count: plan.period_months ?? 1 })
}

/** "14 days", "1 month", "12 months". */
export function planLengthText(plan: Pick<Plan, 'kind' | 'trial_days' | 'period_months'>): string {
  if (plan.kind === 'trial') return fill(text.trialDays, { count: plan.trial_days ?? 0 })
  const months = plan.period_months ?? 1
  return months === 1 ? '1 month' : `${months} months`
}

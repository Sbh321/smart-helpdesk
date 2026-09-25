import { createFileRoute } from '@tanstack/react-router'
import { BillingScreen } from '@/features/billing'

/** The workspace's plan and receipt payments (ADR-0025). */
export const Route = createFileRoute('/$workspace/_app/settings/billing')({
  component: BillingScreen,
})

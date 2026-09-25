import { createFileRoute } from '@tanstack/react-router'
import { PaymentsScreen, paymentsSearchSchema } from '@/features/platform'

/** The receipt review queue (ADR-0025 §3). */
export const Route = createFileRoute('/_platform/platform/payments')({
  validateSearch: paymentsSearchSchema,
  component: function PaymentsPage() {
    const search = Route.useSearch()
    const navigate = Route.useNavigate()
    return (
      <PaymentsScreen
        tab={search.tab}
        page={search.page}
        paymentId={search.payment}
        onChange={(patch) => void navigate({ search: (current) => ({ ...current, ...patch }) })}
      />
    )
  },
})

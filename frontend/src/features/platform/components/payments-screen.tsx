import { useQuery } from '@tanstack/react-query'
import { ReceiptIcon } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'
import { ErrorState } from '@/components/shared/error-state'
import { PageHeader } from '@/components/shared/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { copy } from '@/copy/en'
import { platformPaymentQuery, platformPaymentsQuery } from '../api'
import { PAYMENT_TABS, type PaymentTab, statusForTab } from '../list-schemas'
import { Pager } from './pager'
import { PaymentReviewDialog } from './payment-review-dialog'
import { PaymentsTable } from './payments-table'

const text = copy.platform.payments

/**
 * The review queue (ADR-0025 §3): receipts waiting oldest first, and the decided ones. The tab, the page
 * and the payment under review live in the address, so the email link opens the review directly.
 */
export function PaymentsScreen({
  tab,
  page,
  paymentId,
  onChange,
}: {
  tab: PaymentTab
  page: number
  paymentId: string | undefined
  onChange: (search: { tab?: PaymentTab; page?: number; payment?: string | undefined }) => void
}) {
  const payments = useQuery(platformPaymentsQuery({ status: statusForTab(tab), page }))
  const onPage = payments.data?.data.find((payment) => payment.id === paymentId)
  const fetched = useQuery({
    ...platformPaymentQuery(paymentId ?? ''),
    enabled: paymentId !== undefined && onPage === undefined,
  })
  const reviewing = paymentId === undefined ? null : (onPage ?? fetched.data ?? null)

  return (
    <>
      <PageHeader title={text.title} description={text.description} />
      <Tabs value={tab} onValueChange={(value) => onChange({ tab: value as PaymentTab, page: 1 })}>
        <TabsList aria-label={text.title}>
          {PAYMENT_TABS.map((key) => (
            <TabsTrigger key={key} value={key}>
              {text.tabs[key]}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
      {payments.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : payments.isError ? (
        <ErrorState error={payments.error} onRetry={() => void payments.refetch()} />
      ) : payments.data.data.length === 0 ? (
        <EmptyState
          icon={ReceiptIcon}
          title={tab === 'pending' ? text.emptyTitle : text.emptyOther}
          description={tab === 'pending' ? text.emptyBody : undefined}
        />
      ) : (
        <div className="flex flex-col gap-3">
          <PaymentsTable
            payments={payments.data.data}
            onReview={(payment) => onChange({ payment: payment.id })}
          />
          <Pager
            page={page}
            lastPage={payments.data.meta.last_page}
            onPage={(next) => onChange({ page: next })}
          />
        </div>
      )}
      <PaymentReviewDialog payment={reviewing} onClose={() => onChange({ payment: undefined })} />
    </>
  )
}

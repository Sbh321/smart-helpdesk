import { TriangleAlertIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Hint } from '@/components/ui/tooltip'
import { copy, fill } from '@/copy/en'
import { formatInZone } from '@/lib/datetime/format'
import { formatMoney } from '@/lib/format/money'
import type { Payment } from '../api'
import { PaymentStatusBadge } from './payment-status'

const text = copy.platform.payments

/** Payments as rows: plan and periods, the amount (flagged when it differs from the plan), the review. */
export function PaymentsTable({
  payments,
  showWorkspace = true,
  onReview,
}: {
  payments: Payment[]
  showWorkspace?: boolean
  onReview: (payment: Payment) => void
}) {
  return (
    <Table aria-label={text.tableLabel}>
      <TableHeader>
        <TableRow>
          {showWorkspace ? <TableHead>{text.columns.workspace}</TableHead> : null}
          <TableHead>{text.columns.plan}</TableHead>
          <TableHead className="text-right">{text.columns.amount}</TableHead>
          <TableHead>{text.columns.paidOn}</TableHead>
          <TableHead>{text.columns.method}</TableHead>
          <TableHead>{text.columns.sent}</TableHead>
          <TableHead>{text.columns.status}</TableHead>
          <TableHead>
            <span className="sr-only">{text.review}</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {payments.map((payment) => {
          const name = payment.workspace?.name ?? ''
          const differs = payment.amount_minor !== payment.expected_minor
          return (
            <TableRow key={payment.id}>
              {showWorkspace ? (
                <TableCell className="font-medium">
                  <span className="flex flex-col">
                    {name}
                    <span className="font-mono font-normal text-muted-foreground text-xs">
                      {payment.workspace?.slug}
                    </span>
                  </span>
                </TableCell>
              ) : null}
              <TableCell>{fill(text.periods, { plan: payment.plan.name, count: payment.periods })}</TableCell>
              <TableCell className="text-right tabular-nums">
                <span className="inline-flex items-center justify-end gap-1.5">
                  {differs ? (
                    <Hint
                      label={fill(text.mismatch, {
                        expected: formatMoney(payment.expected_minor, payment.currency),
                      })}
                    >
                      <TriangleAlertIcon
                        role="img"
                        aria-label={fill(text.mismatch, {
                          expected: formatMoney(payment.expected_minor, payment.currency),
                        })}
                        className="size-4 text-warning"
                      />
                    </Hint>
                  ) : null}
                  {formatMoney(payment.amount_minor, payment.currency)}
                </span>
              </TableCell>
              <TableCell className="tabular-nums">
                {formatInZone(`${payment.paid_on}T12:00:00Z`, 'UTC', 'd MMM yyyy')}
              </TableCell>
              <TableCell>
                <span className="flex flex-col">
                  {copy.platform.methods[payment.method]}
                  {payment.reference ? (
                    <span className="font-mono text-muted-foreground text-xs">{payment.reference}</span>
                  ) : null}
                </span>
              </TableCell>
              <TableCell className="tabular-nums">
                {payment.created_at ? formatInZone(payment.created_at, 'UTC', 'd MMM yyyy') : ''}
              </TableCell>
              <TableCell>
                <PaymentStatusBadge status={payment.status} />
              </TableCell>
              <TableCell className="text-right">
                <Button
                  type="button"
                  size="sm"
                  variant={payment.status === 'pending' ? 'default' : 'outline'}
                  aria-label={fill(text.reviewNamed, { name: name || payment.plan.name })}
                  onClick={() => onReview(payment)}
                >
                  {text.review}
                </Button>
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}

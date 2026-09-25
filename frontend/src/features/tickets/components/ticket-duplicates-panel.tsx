import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { StatusBadge } from '@/components/shared/status-badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { hasProblemCode, problemMessage } from '@/lib/api/problem-messages'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan } from '@/lib/auth'
import {
  type DuplicateSuggestion,
  dismissDuplicate,
  duplicateQueries,
  markDuplicate,
} from '../api/duplicate-queries'
import type { Ticket } from '../api/ticket-queries'

export function TicketDuplicatesPanel({
  tenantId,
  workspace,
  ticket,
}: {
  tenantId: string
  workspace: string
  ticket: Ticket
}) {
  const client = useQueryClient()
  const canUpdate = useCan('tickets.update')
  const canClose = useCan('tickets.close')
  const [selected, setSelected] = useState<DuplicateSuggestion | null>(null)
  const suggestions = useQuery({ ...duplicateQueries.list(tenantId, ticket.id), enabled: tenantId !== '' })
  /** 409 `already_decided`: another Agent was faster. Show the current state instead of an error. */
  function decidedElsewhere(error: unknown) {
    if (!hasProblemCode(error, 'already_decided')) return
    setSelected(null)
    void client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
    toast.info(problemMessage(error) ?? copy.problems.alreadyDecided)
  }
  const dismiss = useMutation({
    mutationFn: (candidateId: string) => dismissDuplicate(ticket.id, candidateId),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: queryKeys.tickets.duplicates(tenantId, ticket.id) })
      toast.success(copy.tickets.duplicates.dismissed)
    },
    onError: decidedElsewhere,
  })
  const mark = useMutation({
    mutationFn: (candidateId: string) => markDuplicate(ticket.id, candidateId),
    onSuccess: async () => {
      setSelected(null)
      await client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
      toast.success(copy.tickets.duplicates.marked)
    },
    onError: decidedElsewhere,
  })
  if (suggestions.isPending) return <Skeleton className="h-16 w-full" />
  if (suggestions.isError)
    return <ErrorState error={suggestions.error} onRetry={() => void suggestions.refetch()} />
  if (suggestions.data.length === 0)
    return <p className="text-sm text-muted-foreground">{copy.tickets.duplicates.none}</p>

  return (
    <section aria-label={copy.tickets.detail.duplicates}>
      <ul className="space-y-3">
        {suggestions.data.map((item) => (
          <li key={item.id} className="rounded-lg border border-border p-4 text-sm bg-surface">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <Link
                  className="font-medium underline-offset-2 hover:underline"
                  to="/$workspace/tickets/$ticketId"
                  params={{ workspace, ticketId: item.candidate_ticket_id }}
                >
                  {fill(copy.tickets.number, { number: item.candidate.number })} {item.candidate.title}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <StatusBadge status={item.candidate.status} />
                  <span className="text-muted-foreground tabular-nums">
                    {fill(copy.tickets.duplicates.score, { score: Math.round(item.score * 100) })}
                  </span>
                </div>
                {/* The match details (roadmap M4-06): the words both tickets share are the whole
                    reason for the score, so they read as tokens, not a comma list. */}
                {item.shared_words.length > 0 ? (
                  <div className="mt-2">
                    <p className="text-muted-foreground">
                      {fill(copy.tickets.duplicates.sharedCount, { count: item.shared_words.length })}
                    </p>
                    <ul
                      aria-label={copy.tickets.duplicates.sharedLabel}
                      className="mt-1 flex flex-wrap gap-1"
                    >
                      {item.shared_words.map((word) => (
                        <li
                          key={word}
                          className="rounded-control border border-border bg-muted px-1.5 font-mono text-xs"
                        >
                          {word}
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null}
                <p className="mt-2 text-xs text-muted-foreground">
                  {item.strategy === 'manual'
                    ? copy.tickets.duplicates.byHand
                    : fill(copy.tickets.duplicates.foundBy, {
                        name: item.strategy,
                        version: item.strategy_version ?? '',
                      })}
                </p>
              </div>
              <span className="text-xs text-muted-foreground">
                {copy.tickets.duplicates.decisions[
                  item.decision as keyof typeof copy.tickets.duplicates.decisions
                ] ?? item.decision}
              </span>
            </div>
            {item.decision === 'pending' && canUpdate ? (
              <div className="mt-3 flex gap-2">
                {ticket.status === 'open' && canClose ? (
                  <Button size="sm" onClick={() => setSelected(item)}>
                    {copy.tickets.duplicates.mark}
                  </Button>
                ) : null}
                <Button
                  size="sm"
                  variant="outline"
                  disabled={dismiss.isPending}
                  onClick={() => dismiss.mutate(item.candidate_ticket_id)}
                >
                  {copy.tickets.duplicates.dismiss}
                </Button>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
      {dismiss.isError && !hasProblemCode(dismiss.error, 'already_decided') ? (
        <FormErrorBanner title={copy.tickets.duplicates.dismissFailed} error={dismiss.error} />
      ) : null}
      <Dialog
        open={selected !== null}
        onOpenChange={(open) => {
          if (!open) setSelected(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{copy.tickets.duplicates.confirmTitle}</DialogTitle>
            <DialogDescription>
              {selected
                ? fill(copy.tickets.duplicates.confirmDescription, { number: selected.candidate.number })
                : ''}
            </DialogDescription>
          </DialogHeader>
          {mark.isError && !hasProblemCode(mark.error, 'already_decided') ? (
            <FormErrorBanner title={copy.tickets.duplicates.markFailed} error={mark.error} />
          ) : null}
          <DialogFooter>
            <Button variant="outline" onClick={() => setSelected(null)}>
              {copy.tickets.create.cancel}
            </Button>
            <Button
              disabled={mark.isPending || !selected}
              onClick={() => {
                if (selected) mark.mutate(selected.candidate_ticket_id)
              }}
            >
              {mark.isPending ? copy.tickets.detail.changing : copy.tickets.duplicates.mark}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  )
}

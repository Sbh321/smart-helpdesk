import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ErrorState } from '@/components/shared/error-state'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { useDirectoryNames } from '@/features/agents'
import { type AgentExclusion, exclusionsOf, hasProblemCode, problemMessage } from '@/lib/api/problem-messages'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { formatInZone } from '@/lib/datetime/format'
import {
  type AssignmentPreview,
  assignmentQueries,
  assignTicket,
  autoAssignTicket,
  unassignTicket,
} from '../api/assignment-queries'

/** What the dialog needs of a ticket; it does not import the tickets feature (which imports this one). */
export interface AssignableTicket {
  id: string
  assigned_agent_id: string | null
  team_id: string | null
}

type Exclusion = AssignmentPreview['excluded'][number] | AgentExclusion

/** The reason is a code; the sentence comes from the copy file. An unknown code gets a neutral wording. */
export function exclusionText(item: Exclusion): string {
  const reasons: Record<string, string> = copy.assignment.reasons
  const text = reasons[item.reason] ?? copy.assignment.reasonUnknown
  return item.reason === 'missing_skill' && item.missing_skills?.length
    ? fill(copy.assignment.missingSkills, { skills: item.missing_skills.join(', ') })
    : text
}

export function AssignmentDialog({
  ticket,
  open,
  onOpenChange,
}: {
  ticket: AssignableTicket
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const timeZone = session?.tenant.timezone ?? 'UTC'
  const client = useQueryClient()
  const names = useDirectoryNames(open)
  const candidates = useQuery({
    ...assignmentQueries.candidates(tenantId, ticket.id),
    enabled: open && tenantId !== '' && !ticket.assigned_agent_id,
  })

  /** Assignment moves a ticket, its history, its SLA timers and two Agents' workloads. */
  function refresh(updated: unknown) {
    client.setQueryData(queryKeys.tickets.detail(tenantId, ticket.id), updated)
    void client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
    void client.invalidateQueries({ queryKey: queryKeys.tickets.history(tenantId, ticket.id) })
    void client.invalidateQueries({ queryKey: queryKeys.sla.ticket(tenantId, ticket.id) })
    void client.invalidateQueries({ queryKey: queryKeys.agents.all(tenantId) })
    void client.invalidateQueries({ queryKey: queryKeys.assignment.candidates(tenantId, ticket.id) })
    void client.invalidateQueries({ queryKey: queryKeys.assignment.latest(tenantId, ticket.id) })
  }

  /** The server's view moved on: refetch instead of overwriting the cache with stale data. */
  function refetchAll() {
    void client.invalidateQueries({ queryKey: queryKeys.tickets.all(tenantId) })
    void client.invalidateQueries({ queryKey: queryKeys.agents.all(tenantId) })
    void client.invalidateQueries({ queryKey: queryKeys.assignment.candidates(tenantId, ticket.id) })
  }

  const assign = useMutation({
    mutationFn: (agentId: string | null) =>
      agentId === null ? autoAssignTicket(ticket.id) : assignTicket(ticket.id, agentId),
    onSuccess: (updated) => {
      refresh(updated)
      toast.success(copy.assignment.saved)
      onOpenChange(false)
    },
    onError: (error) => {
      // 409 `already_assigned`: the refetched ticket switches this dialog to the current Agent.
      if (hasProblemCode(error, 'already_assigned')) {
        refetchAll()
        toast.info(problemMessage(error) ?? copy.problems.alreadyAssigned)
      }
      // 422 `no_eligible_agent`: the attempt is stored, so history and the ranking changed.
      if (hasProblemCode(error, 'no_eligible_agent')) refetchAll()
    },
  })
  const refused = exclusionsOf(assign.error)
  const unassign = useMutation({
    mutationFn: () => unassignTicket(ticket.id),
    onSuccess: (updated) => {
      refresh(updated)
      toast.success(copy.assignment.unassigned)
      onOpenChange(false)
    },
  })

  const agentLabel = (id: string, given?: string | null) =>
    given ?? names.agentName(id) ?? copy.assignment.unknownAgent

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{copy.assignment.title}</DialogTitle>
          <DialogDescription>{copy.assignment.description}</DialogDescription>
        </DialogHeader>
        {ticket.assigned_agent_id ? (
          <div className="space-y-3">
            <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
              <dt className="text-muted-foreground">{copy.assignment.currentAgent}</dt>
              <dd>
                {names.isLoading ? <Skeleton className="h-4 w-32" /> : agentLabel(ticket.assigned_agent_id)}
              </dd>
              <dt className="text-muted-foreground">{copy.assignment.currentTeam}</dt>
              <dd>
                {names.isLoading ? (
                  <Skeleton className="h-4 w-32" />
                ) : ticket.team_id ? (
                  (names.teamName(ticket.team_id) ?? copy.assignment.unknownTeam)
                ) : (
                  copy.assignment.noTeam
                )}
              </dd>
            </dl>
            <Button variant="outline" disabled={unassign.isPending} onClick={() => unassign.mutate()}>
              {copy.assignment.unassign}
            </Button>
            {unassign.error ? (
              <FormErrorBanner title={copy.assignment.failed} error={unassign.error} />
            ) : null}
          </div>
        ) : candidates.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : candidates.isError ? (
          <ErrorState error={candidates.error} onRetry={() => void candidates.refetch()} />
        ) : (
          <div className="space-y-4">
            <section aria-labelledby="assignment-explanation" className="space-y-1 text-sm">
              <h3 id="assignment-explanation" className="font-medium">
                {copy.assignment.explanationTitle}
              </h3>
              <p className="text-muted-foreground">{copy.assignment.explanation}</p>
              <p className="text-xs text-muted-foreground">
                {fill(copy.assignment.strategy, {
                  name: candidates.data.strategy,
                  version: candidates.data.strategy_version,
                })}
              </p>
            </section>
            {candidates.data.ranking.length === 0 ? (
              <p className="text-sm">{copy.assignment.none}</p>
            ) : (
              <Table>
                <caption className="sr-only">{copy.assignment.rankingCaption}</caption>
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.assignment.rank}</TableHead>
                    <TableHead>{copy.assignment.agent}</TableHead>
                    <TableHead>{copy.assignment.openTickets}</TableHead>
                    <TableHead>{copy.assignment.load}</TableHead>
                    <TableHead>{copy.assignment.lastAssigned}</TableHead>
                    <TableHead>
                      <span className="sr-only">{copy.assignment.assign}</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {candidates.data.ranking.map((candidate, position) => {
                    const name = agentLabel(candidate.agent_id, candidate.agent_name)
                    return (
                      <TableRow key={candidate.agent_id}>
                        <TableCell className="tabular-nums">{position + 1}</TableCell>
                        <TableCell className="font-medium">{name}</TableCell>
                        <TableCell className="tabular-nums">
                          {fill(copy.assignment.openOfCapacity, {
                            open: candidate.open_tickets,
                            capacity: candidate.capacity,
                          })}
                        </TableCell>
                        <TableCell className="tabular-nums">
                          {fill(copy.assignment.percent, { value: Math.round(candidate.load * 100) })}
                        </TableCell>
                        <TableCell>
                          {candidate.last_assigned_at
                            ? formatInZone(candidate.last_assigned_at, timeZone)
                            : copy.assignment.neverAssigned}
                        </TableCell>
                        <TableCell className="text-right">
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={assign.isPending}
                            aria-label={fill(copy.assignment.assignTo, { name })}
                            onClick={() => assign.mutate(candidate.agent_id)}
                          >
                            {copy.assignment.assign}
                          </Button>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            )}
            {candidates.data.excluded.length > 0 ? (
              <section aria-labelledby="assignment-excluded" className="space-y-1 text-sm">
                <h3 id="assignment-excluded" className="font-medium">
                  {fill(copy.assignment.excluded, { count: candidates.data.excluded.length })}
                </h3>
                <ul className="list-inside list-disc text-muted-foreground">
                  {candidates.data.excluded.map((item) => (
                    <li key={item.agent_id}>
                      {fill(copy.assignment.excludedRow, {
                        name: agentLabel(item.agent_id),
                        reason: exclusionText(item),
                      })}
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
            <Button
              disabled={assign.isPending || candidates.data.ranking.length === 0}
              onClick={() => assign.mutate(null)}
            >
              {copy.assignment.autoAssign}
            </Button>
            {assign.error && !hasProblemCode(assign.error, 'already_assigned') ? (
              <FormErrorBanner title={copy.assignment.failed} error={assign.error} />
            ) : null}
            {refused.length > 0 ? (
              <section aria-labelledby="assignment-refused" className="space-y-1 text-sm">
                <h3 id="assignment-refused" className="font-medium">
                  {fill(copy.assignment.refused, { count: refused.length })}
                </h3>
                <ul className="list-inside list-disc text-muted-foreground">
                  {refused.map((item) => (
                    <li key={item.agent_id}>
                      {fill(copy.assignment.excludedRow, {
                        name: agentLabel(item.agent_id),
                        reason: exclusionText(item),
                      })}
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

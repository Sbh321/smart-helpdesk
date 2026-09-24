import { useQuery } from '@tanstack/react-query'
import { z } from 'zod'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { useDirectoryNames } from '@/features/agents'
import { formatInZone } from '@/lib/datetime/format'
import { assignmentQueries, type TicketAssignment } from '../api/assignment-queries'
import { exclusionText } from './assignment-dialog'

const text = copy.assignment.why

/** What this screen reads of the stored explanation (docs/05-algorithms/agent-assignment.md §Result). */
const explanationSchema = z.object({
  strategy: z.string().optional(),
  strategy_version: z.string().optional(),
  outcome: z.string(),
  selection: z.string().optional(),
  agent_id: z.string().nullable().optional(),
  recommended_agent_id: z.string().nullable().optional(),
  manual_override: z.boolean().optional(),
  override_reason: z.string().optional(),
  ranking: z
    .array(
      z.object({
        rank: z.number(),
        agent_id: z.string(),
        open_tickets: z.number(),
        capacity: z.number(),
      }),
    )
    .default([]),
  excluded: z
    .array(
      z.object({ agent_id: z.string(), reason: z.string(), missing_skills: z.array(z.string()).optional() }),
    )
    .default([]),
})

type Explanation = z.infer<typeof explanationSchema>

/**
 * The sentence for the latest assignment. It is built from the stored strategy output only: what the
 * strategy recommended, what was chosen and whether the choice broke an eligibility rule.
 */
export function assignmentSentence(
  row: Pick<TicketAssignment, 'reason' | 'agent_id'>,
  explanation: Explanation,
  agentName: (id: string) => string,
): string {
  if (row.reason === 'unassign' || explanation.outcome === 'unassigned') return text.unassigned
  const chosen = row.agent_id
  if (chosen === null) return text.noEligible
  const agent = agentName(chosen)

  if (explanation.manual_override) {
    const reason = exclusionText({
      agent_id: chosen,
      reason: explanation.override_reason ?? '',
      missing_skills: explanation.excluded.find((item) => item.agent_id === chosen)?.missing_skills,
    })
    return fill(text.overridden, { agent, reason: reason.toLowerCase() })
  }
  if (row.reason === 'auto') {
    const picked = explanation.ranking.find((candidate) => candidate.agent_id === chosen)
    return fill(explanation.ranking.length === 1 ? text.automaticOnly : text.automatic, {
      agent,
      count: explanation.ranking.length,
      open: picked?.open_tickets ?? 0,
      capacity: picked?.capacity ?? 0,
    })
  }
  const recommended = explanation.recommended_agent_id ?? null
  if (recommended === null) return fill(text.manualNoRecommendation, { agent })
  if (recommended === chosen) return fill(text.manualSame, { agent })
  return fill(text.manualOther, { agent, recommended: agentName(recommended) })
}

/**
 * Why the ticket's Agent was chosen, in the ticket's Assignment section (roadmap M4-06). Before M4-06
 * the reason was only visible in the assignment dialog before choosing; afterwards it was gone. The
 * stored explanation of the latest assignment row is read back, never recomputed, so it says what was
 * true when it was decided. Only for viewers with `tickets.assign`, like the candidate ranking.
 */
export function AssignmentReason({
  tenantId,
  ticketId,
  timeZone,
}: {
  tenantId: string
  ticketId: string
  timeZone: string
}) {
  const latest = useQuery({ ...assignmentQueries.latest(tenantId, ticketId), enabled: tenantId !== '' })
  const names = useDirectoryNames(latest.data != null)

  if (latest.isPending) return <Skeleton className="h-10 w-full" />
  if (latest.isError) return <p className="text-sm text-muted-foreground">{text.failed}</p>
  // Never assigned: the facts above already say "Unassigned".
  if (latest.data === null) return null

  const row = latest.data
  const parsed = explanationSchema.safeParse(row.explanation)
  if (!parsed.success) return null
  const explanation = parsed.data
  const agentName = (id: string) => names.agentName(id) ?? copy.assignment.unknownAgent

  return (
    <section aria-labelledby="assignment-reason" className="mt-3 flex flex-col gap-2 text-sm">
      <h3 id="assignment-reason" className="font-medium">
        {text.title}
      </h3>
      {names.isLoading ? (
        <Skeleton className="h-4 w-full" />
      ) : (
        <p>{assignmentSentence(row, explanation, agentName)}</p>
      )}
      <p className="text-muted-foreground">
        {fill(text.when, { date: formatInZone(row.created_at, timeZone) })}
      </p>
      {explanation.ranking.length > 0 || explanation.excluded.length > 0 ? (
        <details>
          <summary className="cursor-default text-muted-foreground hover:text-foreground">
            {text.showRanking}
          </summary>
          <div className="mt-2 flex flex-col gap-2">
            {explanation.ranking.length > 0 ? (
              <ol aria-label={text.rankingCaption} className="list-inside list-decimal">
                {explanation.ranking.map((candidate) => (
                  <li key={candidate.agent_id}>
                    {fill(text.rankingRow, {
                      name: agentName(candidate.agent_id),
                      open: candidate.open_tickets,
                      capacity: candidate.capacity,
                    })}
                  </li>
                ))}
              </ol>
            ) : null}
            {explanation.excluded.length > 0 ? (
              <div>
                <p className="font-medium">
                  {fill(copy.assignment.excluded, { count: explanation.excluded.length })}
                </p>
                <ul className="list-inside list-disc text-muted-foreground">
                  {explanation.excluded.map((item) => (
                    <li key={item.agent_id}>
                      {fill(copy.assignment.excludedRow, {
                        name: agentName(item.agent_id),
                        reason: exclusionText(item),
                      })}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            {explanation.strategy ? (
              <p className="text-xs text-muted-foreground">
                {fill(copy.assignment.strategy, {
                  name: explanation.strategy,
                  version: explanation.strategy_version ?? '',
                })}
              </p>
            ) : null}
          </div>
        </details>
      ) : null}
    </section>
  )
}

import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTitle, PopoverTrigger } from '@/components/ui/popover'
import { copy, fill } from '@/copy/en'

const explanationSchema = z.object({
  strategy: z.string(),
  strategy_version: z.string(),
  parts: z.array(
    z.object({ name: z.string(), value: z.number(), weight: z.number(), contribution: z.number() }),
  ),
})

export type PriorityExplanationData = z.infer<typeof explanationSchema>

const text = copy.tickets.detail
const factorNames = copy.priority.weightNames as Record<string, string>

/** One decimal, the precision the score is shown with everywhere else. */
function points(value: number): string {
  return (Math.round(value * 10) / 10).toFixed(1)
}

/**
 * The factors that made the score, largest first, with each one's share of it. The score is the sum of
 * the stored contributions, so the bars always add up to what the ticket shows (roadmap M4-06).
 */
export function explainPriority(data: PriorityExplanationData) {
  const score = data.parts.reduce((sum, part) => sum + part.contribution, 0)
  const factors = [...data.parts]
    .sort((a, b) => b.contribution - a.contribution)
    .map((part) => ({
      name: part.name,
      label: factorNames[part.name] ?? part.name,
      contribution: part.contribution,
      share: score > 0 ? part.contribution / score : 0,
    }))
  return { score, factors }
}

/**
 * Why a ticket has its priority, in words first and numbers second (roadmap M4-06). The stored
 * strategy output is read, never recomputed.
 *
 * Before M4-06 the popover was only the calculation table (`impact 0.3333 · 0.4 · 13.3333`): correct
 * and unreadable. Now a sentence names the factors that decided it, a bar per factor shows its share of
 * the score, and the exact table stays one click away for anyone checking the arithmetic
 * (docs/06-design-system/ux-review.md G5, principle D5).
 */
export function PriorityExplanation({ data, reason }: { data: unknown; reason?: string | null }) {
  const parsed = explanationSchema.safeParse(data)
  const explained = parsed.success ? explainPriority(parsed.data) : null
  const deciding = explained?.factors.filter((factor) => factor.contribution > 0).slice(0, 2) ?? []

  return (
    <Popover>
      <PopoverTrigger render={<Button size="sm" variant="ghost" />}>{text.whyPriority}</PopoverTrigger>
      <PopoverContent className="w-[min(26rem,calc(100vw-2rem))]">
        <PopoverTitle>{text.whyPriority}</PopoverTitle>
        {reason ? (
          <p className="rounded-control border border-border p-2">
            <span className="font-medium">{text.manual}:</span> {reason}
          </p>
        ) : null}

        {parsed.success && explained ? (
          <>
            <p>
              {fill(text.scoreSentence, { score: points(explained.score) })}{' '}
              {deciding.length > 0
                ? fill(deciding.length === 1 ? text.decidedByOne : text.decidedByTwo, {
                    first: deciding[0]?.label.toLowerCase() ?? '',
                    second: deciding[1]?.label.toLowerCase() ?? '',
                  })
                : null}
            </p>

            <ul aria-label={text.factorsLabel} className="flex flex-col gap-2">
              {explained.factors.map((factor) => (
                <li key={factor.name} className="flex flex-col gap-1">
                  <div className="flex items-baseline justify-between gap-2 text-sm">
                    <span>{factor.label}</span>
                    <span className="text-muted-foreground tabular-nums">
                      {fill(text.pointsOf, { points: points(factor.contribution) })}
                    </span>
                  </div>
                  {/* The number above is the accessible value; the bar only shows it at a glance. */}
                  <div aria-hidden="true" className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-primary"
                      style={{ width: `${Math.round(factor.share * 100)}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>

            <details className="text-sm">
              <summary className="cursor-default text-muted-foreground hover:text-foreground">
                {text.showCalculation}
              </summary>
              <p className="mt-2 text-muted-foreground">
                {text.strategy}: {parsed.data.strategy} · {parsed.data.strategy_version}
              </p>
              <div className="mt-1 overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr>
                      {[text.factor, text.value, text.weight, text.contribution].map((label) => (
                        <th key={label} scope="col" className="p-2">
                          {label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {parsed.data.parts.map((part) => (
                      <tr key={part.name} className="border-border border-t">
                        <th scope="row" className="p-2 font-medium">
                          {part.name}
                        </th>
                        <td className="p-2 tabular-nums">{part.value}</td>
                        <td className="p-2 tabular-nums">{part.weight}</td>
                        <td className="p-2 tabular-nums">{part.contribution}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          </>
        ) : (
          <p className="text-muted-foreground">{text.noExplanation}</p>
        )}
      </PopoverContent>
    </Popover>
  )
}

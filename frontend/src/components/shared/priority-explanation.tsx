import { z } from 'zod'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTitle, PopoverTrigger } from '@/components/ui/popover'
import { copy } from '@/copy/en'

const explanationSchema = z.object({
  strategy: z.string(),
  strategy_version: z.string(),
  parts: z.array(
    z.object({ name: z.string(), value: z.number(), weight: z.number(), contribution: z.number() }),
  ),
})

/** Stored strategy output only: the UI never recomputes priority. */
export function PriorityExplanation({ data, reason }: { data: unknown; reason?: string | null }) {
  const parsed = explanationSchema.safeParse(data)
  const text = copy.tickets.detail
  return (
    <Popover>
      <PopoverTrigger render={<Button size="sm" variant="ghost" />}>{text.whyPriority}</PopoverTrigger>
      <PopoverContent className="w-[min(32rem,calc(100vw-2rem))]">
        <PopoverTitle>{text.whyPriority}</PopoverTitle>
        {reason ? (
          <p>
            {text.manual}: {reason}
          </p>
        ) : null}
        {parsed.success ? (
          <>
            <p className="text-muted-foreground">
              {text.strategy}: {parsed.data.strategy} · {parsed.data.strategy_version}
            </p>
            <div className="overflow-x-auto">
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
                    <tr key={part.name} className="border-t border-border">
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
          </>
        ) : (
          <p className="text-muted-foreground">{text.noExplanation}</p>
        )}
      </PopoverContent>
    </Popover>
  )
}
